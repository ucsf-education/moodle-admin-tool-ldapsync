<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * script for bulk user delete operations
 * @package tool_ldapsync
 */

require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$confirm = optional_param('confirm', 0, PARAM_BOOL);

admin_externalpage_setup('userbulk');
require_capability('moodle/user:delete', context_system::instance());

$return = $CFG->wwwroot . '/' . $CFG->admin . '/tool/ldapsync/user.php';

if (empty($SESSION->ufiltering)) {
    redirect($return);
}

$ufiltering = unserialize($SESSION->ufiltering);
$bulkusers = [];

[$sqlwhere, $params] = $ufiltering->get_sql_filter("id<>:exguest AND deleted <> 1", ['exguest' => $CFG->siteguest]);

$rs = $DB->get_recordset_select('user', $sqlwhere, $params, 'fullname', 'id,' . $DB->sql_fullname() . ' AS fullname');
foreach ($rs as $user) {
    if (!isset($bulkusers[$user->id])) {
        $bulkusers[$user->id] = $user->id;
    }
}
$rs->close();

echo $OUTPUT->header();

if ($confirm && confirm_sesskey()) {
    $notifications = '';
    [$in, $params] = $DB->get_in_or_equal($bulkusers);
    $rs = $DB->get_recordset_select('user', "deleted = 0 and id $in", $params);
    foreach ($rs as $user) {
        if (!is_siteadmin($user) && $USER->id != $user->id && delete_user($user)) {
            unset($bulkusers[$user->id]);
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
    [$in, $params] = $DB->get_in_or_equal($bulkusers);
    $userlist = $DB->get_records_select_menu('user', "id $in", $params, 'fullname', 'id,' . $DB->sql_fullname() . ' AS fullname');
    $usernames = implode(', ', $userlist);
    echo $OUTPUT->heading(get_string('confirmation', 'admin'));
    $formcontinue = new single_button(new moodle_url('user_bulk_delete.php', ['confirm' => 1]), get_string('yes'));
    $formcancel = new single_button(new moodle_url('user.php'), get_string('no'), 'get');
    echo $OUTPUT->confirm(get_string('deletecheckfull', '', $usernames), $formcontinue, $formcancel);
}

echo $OUTPUT->footer();
