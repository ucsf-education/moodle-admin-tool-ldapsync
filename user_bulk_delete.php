<?php
/**
* script for bulk user delete operations
*/

require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

$confirm = optional_param('confirm', 0, PARAM_BOOL);

admin_externalpage_setup('userbulk');
require_capability('moodle/user:delete', context_system::instance());

$return = $CFG->wwwroot.'/'.$CFG->admin.'/tool/ldapsync/user.php';

if (empty($SESSION->ufiltering)) {
    redirect($return);
}

$ufiltering = unserialize($SESSION->ufiltering);
$bulk_users = array();

list($sqlwhere, $params) = $ufiltering->get_sql_filter("id<>:exguest AND deleted <> 1", array('exguest'=>$CFG->siteguest));

$rs = $DB->get_recordset_select('user', $sqlwhere, $params, 'fullname', 'id,'.$DB->sql_fullname().' AS fullname');
foreach ($rs as $user) {
    if (!isset($bulk_users[$user->id])) {
        $bulk_users[$user->id] = $user->id;
    }
}
$rs->close();

echo $OUTPUT->header();

if ($confirm and confirm_sesskey()) {
    $notifications = '';
    list($in, $params) = $DB->get_in_or_equal($bulk_users);
    $rs = $DB->get_recordset_select('user', "deleted = 0 and id $in", $params);
    foreach ($rs as $user) {
        if (!is_siteadmin($user) and $USER->id != $user->id and delete_user($user)) {
            unset($bulk_users[$user->id]);
        } else {
            $notifications .= $OUTPUT->notification(get_string('deletednot', '', fullname($user, true)));
        }
    }
    $rs->close();
    \core\session\manager::gc(); // Remove stale sessions.
    echo $OUTPUT->box_start('generalbox', 'notice');
    if (!empty($notifications)) {
        echo $notifications;
    } else {
        echo $OUTPUT->notification(get_string('changessaved'), 'notifysuccess');
    }
    $continue = new single_button(new moodle_url($return), get_string('continue'), 'post');
    echo $OUTPUT->render($continue);
    echo $OUTPUT->box_end();
} else {
    list($in, $params) = $DB->get_in_or_equal($bulk_users);
    $userlist = $DB->get_records_select_menu('user', "id $in", $params, 'fullname', 'id,'.$DB->sql_fullname().' AS fullname');
    $usernames = implode(', ', $userlist);
    echo $OUTPUT->heading(get_string('confirmation', 'admin'));
    $formcontinue = new single_button(new moodle_url('user_bulk_delete.php', array('confirm' => 1)), get_string('yes'));
    $formcancel = new single_button(new moodle_url('user.php'), get_string('no'), 'get');
    echo $OUTPUT->confirm(get_string('deletecheckfull', '', $usernames), $formcontinue, $formcancel);
}

echo $OUTPUT->footer();
