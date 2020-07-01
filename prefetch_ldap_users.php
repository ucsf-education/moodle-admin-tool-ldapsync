<?php

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

admin_externalpage_setup('ldapsync_prefetch');

$return = $CFG->wwwroot.'/'.$CFG->admin.'/tool/ldapsync/user.php';

echo $OUTPUT->header();

$sync = new \tool_ldapsync\importer();

$cachedir = $CFG->cachedir.'/misc';
$cachefile = $cachedir . '/ldapsync_userlist.json';
if (file_exists($cachefile)) {
    unlink( $cachefile );
}

$userlist = $sync->ldap_get_userlist();

if (!empty($userlist)) {

    if (!file_exists($cachedir)) {
        mkdir($cachedir, $CFG->directorypermissions, true);
    }

    file_put_contents($cachefile, json_encode($userlist));
    echo "A total of ". count($userlist) . " active users found on LDAP.";
}

echo $OUTPUT->continue_button($return);
echo $OUTPUT->footer();
