<?php

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

admin_externalpage_setup('ldapsync_testsettings');

$return = $CFG->wwwroot.'/'.$CFG->admin.'/settings.php?section=ldapsync_settings';

echo $OUTPUT->header();

$sync = new \tool_ldapsync\importer();
$sync->test_settings();

echo $OUTPUT->continue_button($return);
echo $OUTPUT->footer();
