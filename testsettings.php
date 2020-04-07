<?php

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

admin_externalpage_setup('ldapsync_testsettings');

$return = $CFG->wwwroot.'/'.$CFG->admin.'/tool/ldapsync/user_bulk_purge.php';

echo $OUTPUT->header();

$sync = new \tool_ldapsync\importer();
$sync->test_settings();

echo $OUTPUT->continue_button($return);
echo $OUTPUT->footer();

// // Check for duplicates
//
// $userlist = $sync->ldap_get_userlist();
// echo "Total number of LDAP users in userlist: ". count($userlist);

// echo "<br />";
// // Remove duplicates
// $newlist = array_unique($userlist);
// echo "Total number of LDAP users after removed duplicates: ". count($newlist);
