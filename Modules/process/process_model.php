<?php
/*
 All Emoncms code is released under the GNU Affero General Public License.
 See COPYRIGHT.txt and LICENSE.txt.
 ---------------------------------------------------------------------
 Emoncms - open source energy visualisation
 Part of the OpenEnergyMonitor project: http://openenergymonitor.org
 */

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

class ProcessError {
    const NONE = 0;
    const TOO_MANY_ITERATIONS = 1;
    const ACCESS_FORBIDDEN = 2;
}

class ProcessOriginType {
    const INPUT = 1;
    const VIRTUALFEED = 2;
    const TASK = 3;
}

class Process
{
    public $mysqli;
    public $input;
    public $feed;
    public $timezone = 'UTC';

    public $proc_initialvalue;  // save the input value at beginning of the processes list execution
    public $proc_skip_next;     // skip execution of next process in process list
    public $proc_goto;          // goto step in process list
    
    public $runtime_error = ProcessError::NONE;  // Errors that occured at runtime
    
    private $log;
    private $modules_functions = array();
    
    private $process_list = array();
    public $process_map = array();
    
    public function __construct($mysqli,$input,$feed,$timezone)
    {
        $this->mysqli = $mysqli;
        $this->input = $input;
        $this->feed = $feed;
        if (!($timezone === NULL)) $this->timezone = $timezone;
        $this->log = new EmonLogger(__FILE__);
        
        $this->process_list = $this->get_process_list(); // Load modules modules
        
        // Build map of processids where set
        foreach ($this->process_list as $k=>$v) {
            if (isset($v['id_num'])) $this->process_map[$v['id_num']] = $k;
        }
    }

    // Triggered when invoking inaccessible methods in this class context, it must be a module function then
    public function __call($method, $args){
        if (strpos($method, '__') === FALSE) {
            $module = "process";              // default to core module 'process'
        } else {
            $mod_fun = explode('__',$method);  // if method contains a '__', assume the format is module__function
            $module = $mod_fun[0];
            $method = $mod_fun[1];
        }
        if(isset($this->modules_functions[$module][$method])) {
            $args[] = &$this;
            return call_user_func_array(array($this->modules_functions[$module][$method], $method), $args);
        } else {
            $this->log->error("__call() Call to undefined module method. Missing function on module? method=$method ");
            throw new Exception("ABORTED: Call to undefined module method. Missing function on module? method=$method");
        }
    }

    public function get_process_list()
    {
        static $list = array(); // Array to hold the cache
        if (empty($list) || empty($this->modules_functions)) {     // Cache it now
            $list=$this->load_modules();  
        }
        return $list;
    }

    public function input($time, $value, $processList, $options = null)
    {
        //$this->log->info("input() received time=$time\tvalue=$value");

        $this->proc_initialvalue = $value; // save the input value at beginning of the processes list execution
        $this->proc_skip_next = false;     // skip execution of next process in process list

        $process_list = $this->get_process_list();
        $pairs = explode(",",$processList);
        $total = count($pairs);
        $steps=0;
        // if ($total>50) return false;

        for ($this->proc_goto=0; $this->proc_goto<$total; $this->proc_goto++) {
            $steps++;
            $inputprocess = explode(":", $pairs[$this->proc_goto]);  // Divide into process key and arg
            $processkey = $inputprocess[0];                          // Process id
            
            // Map ids to process key names
            if (isset($this->process_map[$processkey])) $processkey = $this->process_map[$processkey];
            
            if (!isset($process_list[$processkey])) {
                $this->log->error("input() Processor '".$processkey."' does not exists. Module missing?");
                return false;
            }
            $arg = 0;
            if (isset($inputprocess[1])) $arg = $inputprocess[1];    // Can be value or feed id
            
            // When process is MULTI, arg is a json base64 encoded of multiple args, decode and pass as array to processor function
            if ($process_list[$processkey]["argtype"] == ProcessArg::MULTI) {
                $jsonString = base64_decode($arg, true);
                if ($jsonString === false) {
                    $this->log->error("input() Invalid Base64 string for function '" . $process_list[$processkey]["function"] . "'");
                } else {
                    $argsarray = json_decode($jsonString, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->log->error("input() Invalid JSON format '" . $process_list[$processkey]["function"] . "' Error: " . json_last_error_msg());
                    } else {
                        $arg = $argsarray; // args array
                        //$this->log->info("input() Multi arguments process function '" . $process_list[$processkey]["function"]. "' : " . print_r($arg, true));
                    }
                }
            }
            
            $process_function = $processkey;                               // get process key 'module.function'
            if (strpos($processkey, '__') === FALSE) $process_function = $process_list[$processkey]["function"]; // Is this line needed??
                
            $not_for_virtual_feeds = array('publish_to_mqtt','eventp__sendemail');
            if (in_array($process_function, $not_for_virtual_feeds) && isset($options['sourcetype']) && $options['sourcetype']==ProcessOriginType::VIRTUALFEED) {
                $this->log->error('Publish to MQTT and SendMail blocked for Virtual Feeds');
            } else {
                $value = $this->$process_function($arg,$time,$value,$options); // execute process function
            }
            
            if ($this->proc_skip_next) {
                $this->proc_skip_next = false; $this->proc_goto++;
            }

            if ($steps > $total*2) {
                // We are executing a looping processlist or too much gotos
                // need to add 'error_found' process to this processList.
                $this->runtime_error = ProcessError::TOO_MANY_ITERATIONS;
                $this->log->error("input() DEACTIVATED processList due to too many steps. steps=$steps proc_goto=".$this->proc_goto." processkey=$processkey sourcetype=" . $options['sourcetype'] . " sourceid=" . $options['sourceid'] );
                switch ($options['sourcetype']) {
                    case ProcessOriginType::INPUT:
                         $this->input->set_processlist($options['sourceid'],"process__error_found:0,".$processList);
                         break;
                         
                    case ProcessOriginType::VIRTUALFEED:
                         $this->feed->set_processlist($options['sourceid'],"process__error_found:0,".$processList);
                         break;
                    case ProcessOriginType::TASK:
                        if (file_exists("Modules/task/task_model.php")) {
                            global $session, $redis;
                            require_once "Modules/task/task_model.php";
                            $this->task = new Task($this->mysqli, $redis, null);
                            $this->task->set_processlist($session['userid'], $options['sourceid'], "process__error_found:0," . $processList);
                        }
                }
                return false;
            }
        }
        return $value;
    }

    private function load_modules() {
        $list = array();

        // Always load the process module processes first
        $modules = array("process");
        
        // Scan all other modules for process lists
        $dir = scandir("Modules");        
        for ($i=2; $i<count($dir); $i++) {
            $module = $dir[$i];
            if (filetype("Modules/$module")=='dir' || filetype("Modules/$module")=='link') {
                if ($module!="process") $modules[] = $module;
            }
        }
        
        // Load processes from selected modules
        for ($i=0; $i<count($modules); $i++) {
            $class = $this->get_module_class($modules[$i]);
            if ($class != null) {
                
                $mod_process_list = $class->process_list();
                
                foreach($mod_process_list as $k => $v) {
                    $processkey = strtolower($modules[$i]."__".$v['function']);
                    $list[$processkey] = $v; // set list key as "module__function"
                    //$this->log->info("load_modules() module=$dir[$i] function=$v[2]"); 
                }
            }
        }
        return $list;
    }

    private function get_module_class($module_name){
        /*
            magic function __call (above) MUST BE USED with this.
            Load additional processlist module files.
            Looks in the folder Modules/modulename/ for a file modulename_processlist.php 
            (module_name all lowercase but class Modulename_ProcessList in php file that is with upper case first letter)
        */
        $module_file = "Modules/".$module_name."/".$module_name."_processlist.php";
        $module_class=null;
        if(file_exists($module_file)){
            require_once($module_file);
            $module_class_name = ucfirst(strtolower($module_name)."_ProcessList");
            $module_class = new $module_class_name($this); // passes this class as reference
            $module_class_functions = get_class_methods($module_class);
            foreach($module_class_functions as $key => $function_name){
                if (substr($function_name, 0, 2) == "__" || $function_name == "process_list") continue;
                $this->modules_functions[strtolower($module_name)][strtolower($function_name)] = &$module_class;
            }
        }
        return $module_class;
    }

}
