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
 * Bulk export user into any dataformat
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @copyright  2007 Petr Skoda
 * @package    tool_ldapsync
 */

define('NO_OUTPUT_BUFFERING', true);
require_once('../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

$dataformat = optional_param('dataformat', '', PARAM_ALPHA);

admin_externalpage_setup('ldapsync_purgeusers');
require_capability('moodle/user:update', context_system::instance());

if (empty($SESSION->ufiltering)) {
    redirect(new moodle_url('/admin/tool/ldapsync/user.php'));
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

if ($dataformat) {
    $fields = ['id'        => 'id',
                    'username'  => 'username',
                    'email'     => 'email',
                    'firstname' => 'firstname',
                    'lastname'  => 'lastname',
                    'idnumber'  => 'idnumber',
                    'institution' => 'institution',
                    'department' => 'department',
                    'phone1'    => 'phone1',
                    'phone2'    => 'phone2',
                    'city'      => 'city',
                    'url'       => 'url',
                    'icq'       => 'icq',
                    'skype'     => 'skype',
                    'aim'       => 'aim',
                    'yahoo'     => 'yahoo',
                    'msn'       => 'msn',
                    'country'   => 'country'];

    if ($extrafields = $DB->get_records('user_info_field')) {
        foreach ($extrafields as $n => $field) {
            $fields['profile_field_' . $field->shortname] = 'profile_field_' . $field->shortname;
            require_once($CFG->dirroot . '/user/profile/field/' . $field->datatype . '/field.class.php');
        }
    }

    $filename = clean_filename(get_string('users'));

    $downloadusers = new ArrayObject($bulkusers);
    $iterator = $downloadusers->getIterator();

    \core\dataformat::download_data($filename, $dataformat, $fields, $iterator, function($userid) use ($extrafields, $fields) {
        global $DB;
        $row = [];
        if (!$user = $DB->get_record('user', ['id' => $userid])) {
            return null;
        }
        foreach ($extrafields as $field) {
            $newfield = 'profile_field_' . $field->datatype;
            $formfield = new $newfield($field->id, $user->id);
            $formfield->edit_load_user_data($user);
        }
        $userprofiledata = [];
        foreach ($fields as $field => $unused) {
            // Custom user profile textarea fields come in an array
            // The first element is the text and the second is the format.
            // We only take the text.
            if (is_array($user->$field)) {
                $userprofiledata[$field] = reset($user->$field);
            } else {
                $userprofiledata[$field] = $user->$field;
            }
        }
        return $userprofiledata;
    });

    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('download', 'admin'));
echo $OUTPUT->download_dataformat_selector(get_string('userbulkdownload', 'admin'), 'user_bulk_download.php');
echo $OUTPUT->footer();
