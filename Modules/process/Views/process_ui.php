<?php
    defined('EMONCMS_EXEC') or die('Restricted access');
    global $path, $settings;
    load_language_files(dirname(__DIR__).'/locale', "process_messages");
    
    // settings.ini parse_ini_file does not convert [0,6,8,10] into an array
    // while settings.php engines_hidden will be an array
    // we convert here the array form to a string which is then passed below
    // to the process ui javascript side of things which coverts to a js array
    $engine_hidden = $settings["feed"]['engines_hidden'];
    if (is_array($engine_hidden)) $engine_hidden = json_encode($engine_hidden);
?>
<style>
  .modal-processlist {
    width: 94%; left: 3%; /* (100%-width)/2 */
    margin-left:auto; margin-right:auto; 
    overflow-y: hidden;
  }
  .modal-processlist .modal-body {
     max-height: none; 
     overflow-y: auto;
  }

  #process-table th:nth-of-type(6), td:nth-of-type(6) {
   text-align: right;
  }

  #new-feed-tag_autocomplete-list{width: 120px}
   
  #process-header-add,
  #process-header-edit {
    font-weight: bold;
    font-size: 16px;
  }

  .type-multi {
    display: flex;
    flex-wrap: wrap;
    column-gap: 2rem; 
    align-items: flex-start;
    margin-top: 1rem;
  }

  .type-multi .form-element {
    display: flex;
    flex-direction: column;
    min-width: 100px;
  }

  .type-multi input,
  .type-multi select {
    padding: 4px;
    width: 100%;
  }
</style>
<script type="text/javascript"><?php require "Modules/process/process_langjs.php"; ?></script>
<script type="text/javascript" src="<?php echo $path; ?>Modules/process/Views/process_ui.js?v=<?php echo $v; ?>"></script>
<script type="text/javascript" src="<?php echo $path; ?>Lib/misc/autocomplete.js?v=<?php echo $v; ?>"></script>
<link rel="stylesheet" href="<?php echo $path; ?>Lib/misc/autocomplete.css?v=<?php echo $v; ?>">
<script>
  processlist_ui.engines_hidden = <?php echo $engine_hidden; ?>;
  <?php if ($settings["redis"]["enabled"]) echo "processlist_ui.has_redis = 1;"; ?>

  $(window).resize(function(){
    processlist_ui.adjustmodal() 
  });
</script>

<div id="processlistModal" class="modal hide keyboard modal-processlist" tabindex="-1" role="dialog" aria-labelledby="processlistModalLabel" aria-hidden="true" data-backdrop="static">
    <div class="modal-header">
        <button type="button" class="close" id="close">×</button>
        <h3><b><span id="contextname"></span></b> <?php echo dgettext('process_messages','process list setup'); ?></h3>
    </div>
    <div class="modal-body" id="processlist-ui">
        <p><?php echo dgettext('process_messages','Processes are executed sequentially with the result value being passed down for further processing to the next processor on this processing list.'); ?></p>

            <div id="noprocess" class="alert"><?php echo dgettext('process_messages','You have no processes defined'); ?></div>

			<div id="process-controls" class="controls">
				<button id="select-all-lines" class="btn" title="<?php echo _('Select All') ?>" data-alt-title="<?php echo _('Unselect All') ?>"><i class="icon icon-check"></i></button>
				<button class="btn process-cut hide" title="<?php echo _('Cut') ?>" style="display: inline-block;"><span class="icon">&#9986;</span></button>
				<button class="btn process-copy hide" title="<?php echo _('Copy') ?>" style="display: inline-block;"><span class="icon">&#9112;</span></button>
				<button class="btn process-paste hide" title="<?php echo _('Paste') ?>" style="display: inline-block;"><i class="icon icon-briefcase"></i></button>
				<button class="btn process-delete hide" title="<?php echo _('Delete') ?>" style="display: inline-block;"><i class="icon icon-trash"></i></button>
			</div>

            <table id="process-table" class="table table-hover">
                <tr>
					<th></th>
                    <th><?php echo dgettext('process_messages','Order'); ?></th>
                    <th style="width:40%;"><?php echo dgettext('process_messages','Process'); ?></th>
                    <th style="text-align:right;opacity:.8" title="<?php echo dgettext('process_messages','Hover over the short names below to get the full description'); ?>"><i class="icon icon-question-sign"></i></th>
                    <th style="width:40%;"><?php echo dgettext('process_messages','Arguments'); ?></th>
                    <th><span class="hidden-md"><?php echo dgettext('process_messages','Latest'); ?></span></th>
                    <th colspan='3'><?php echo dgettext('process_messages','Actions'); ?></th>
                </tr>
                <tbody id="process-table-elements"></tbody>
            </table>

            <table class="table">
            <tr>
                <td>
                    <p>
                        <span id="process-header-add"><?php echo dgettext('process_messages','Add process'); ?>:
                            <a href="#" onclick="selectProcess(event)" class="label label-info" data-processid="process__log_to_feed">log</a>
                            <a href="#" onclick="selectProcess(event)" class="label label-info" data-processid="process__power_to_kwh">kwh</a>
                            <a href="#" onclick="selectProcess(event)" class="label label-warning" data-processid="process__add_input">+inp</a>
                        </span>
                        <span id="process-header-edit"><?php echo dgettext('process_messages','Edit process'); ?>:</span>
                    </p>

                    <select id="process-select" class="input-large"></select>
                    
                    <span id="type-multi" style="display:none">
                        <div id="process-header-add"><?php echo dgettext('process_messages','Arguments'); ?></div>
                        <div id="type-multi-args" class="type-multi"></div>
                    </span>
                    
                    <span id="type-value" style="display:none">
                        <div class="input-prepend">
                            <span class="add-on value-select-label"><?php echo dgettext('process_messages','Value'); ?></span>
                            <input type="text" id="value-input" class="input-medium" placeholder="<?php echo dgettext('process_messages','Type value...'); ?>" />
                        </div>
                    </span>
                    
                    <span id="type-text" style="display:none">
                        <div class="input-prepend">
                            <span class="add-on text-select-label"><?php echo dgettext('process_messages','Text'); ?></span>
                            <input type="text" id="text-input" class="input-large" placeholder="<?php echo dgettext('process_messages','Type text...'); ?>" />
                        </div>
                    </span>

                    <span id="type-input" style="display:none">
                        <div class="input-prepend">
                            <span class="add-on input-select-label"><?php echo dgettext('process_messages','Input'); ?></span>                   
                            <div class="btn-group">
                                <select id="input-select" class="input-medium"></select>
                            </div>
                        </div>
                    </span>

                    <span id="type-schedule" style="display:none">
                        <div class="input-prepend">
                            <span class="add-on schedule-select-label"><?php echo dgettext('process_messages','Schedule'); ?></span>
                            <div class="btn-group">
                                <select id="schedule-select" class="input-large"></select>
                            </div>
                        </div>
                    </span>
                    
                    <span id="type-feed"> 
                                                    
                        <div class="input-prepend">
                            <span class="add-on feed-select-label"><?php echo dgettext('process_messages','Feed'); ?></span>
                            <div class="btn-group">
                                <select id="feed-select" class="input-medium" style="border-bottom-right-radius: 0;border-top-right-radius: 0;"></select>
                                <div class="autocomplete">
                                    <input id="new-feed-tag" type="text" pattern="[a-zA-Z0-9-_: ]+" required style="width:4em; border-right: none; border-bottom-right-radius: 0; border-top-right-radius: 0;" title="<?php echo dgettext('process_messages','Please enter a feed tag consisting of alphabetical letters, A-Z a-z 0-9 - _ : and spaces'); ?>" placeholder="<?php echo dgettext('process_messages','Tag'); ?>" />
                                </div>
                                <input id="new-feed-name" type="text" pattern="[a-zA-Z0-9-_: ]+" required style="width:6em" title="<?php echo dgettext('process_messages','Please enter a feed name consisting of alphabetical letters, A-Z a-z 0-9 - _ : and spaces'); ?>" placeholder="<?php echo dgettext('process_messages','Name'); ?>" />
                            </div>
                        </div>
                        
                        <div class="input-prepend">
                            <span class="add-on feed-engine-label"><?php echo dgettext('process_messages','Engine'); ?></span>
                            <div class="btn-group">
                                <select id="feed-engine" class="input-medium">
                                    <?php foreach (Engine::get_all_descriptive() as $engine) { ?>
                                    <option value="<?php echo $engine["id"]; ?>"><?php echo $engine["description"]; ?></option>
                                    <?php } ?>
                                </select>
                                <select id="feed-interval" class="input-mini">
                                    <option value=""><?php echo dgettext('process_messages','Select interval'); ?></option>                    
                                    <?php foreach (Engine::available_intervals() as $i) { ?>
                                    <option value="<?php echo $i["interval"]; ?>"><?php echo dgettext('process_messages',$i["description"]); ?></option>
                                    <?php } ?>
                                </select>
                                <?php if (isset($settings["feed"]["mysqltimeseries"]) && isset($settings["feed"]["mysqltimeseries"]["generic"]) && !$settings["feed"]["mysqltimeseries"]["generic"]) { ?>
                                <input id="feed-table" type="text" pattern="[a-zA-Z0-9_]+" style="width:6em" title="<?php echo dgettext('process_messages','Please enter a table name consisting of alphabetical letters, A-Z a-z 0-9 and _ characters'); ?>" placeholder="<?php echo dgettext('process_messages','Table'); ?>" />
                                <?php } ?>
                            </div>
                        </div>
                    </span>
                    <span id="type-btn-add">
                        <div class="input-prepend">
                            <button id="process-add" class="btn btn-info" style="border-radius: 4px;"><?php echo dgettext('process_messages','Add'); ?></button>
                        </div>
                    </span>
                    <span id="type-btn-edit" style="display:none">
                        <div class="input-prepend">
                            <button id="process-edit" class="btn btn-info" style="border-radius: 4px;"><?php echo dgettext('process_messages','Edit'); ?></button>
                        </div>
                        <div class="input-prepend">
                            <button id="process-cancel" class="btn" style="border-radius: 4px;"><?php echo dgettext('process_messages','Cancel'); ?></button>
                        </div>
                    </span>
                </td>
            </tr>
            <tr>
              <td><div id="description" class="alert alert-info"></div></td>
            </tr>
            </table>
    </div>
    <div class="modal-footer">
        <button class="btn" id="close"><?php echo dgettext('process_messages','Close'); ?></button>
        <button id="save-processlist" class="btn btn-success" style="float:right"><?php echo dgettext('process_messages','Not modified'); ?></button>
    </div>
</div>
