<?php

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

admin_externalpage_setup('ldapsync_prefetch');

$return = $CFG->wwwroot.'/'.$CFG->admin.'/tool/ldapsync/user.php';

echo $OUTPUT->header();

$userlist = $sync->ldap_get_userlist();

if (!empty($userlist)) {

    $cachedir = $CFG->cachedir.'/misc';

    if (!file_exists($cachedir)) {
        mkdir($cachedir, $CFG->directorypermissions, true);
    }

    $cachefile = $cachedir . '/ldapsync_userlist.json';

    if (file_exists($cachfile)) {
        unlink( $cachfile );
    }

    file_put_contents($cachefile, json_encode($userlist));

    echo "A total of ". count($userlist) . " active users found on LDAP.";
}

echo $OUTPUT->continue_button($return);
echo $OUTPUT->footer();
