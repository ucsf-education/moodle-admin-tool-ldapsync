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
 * Display Page for Bulk Users
 *
 * @package     tool_ldapsync
 * @copyright   Copyright (c) 2024, UCSF Education IT
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '../../../config.php');

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

$sort = optional_param('sort', 'fullname', PARAM_ALPHA);
$dir  = optional_param('dir', 'asc', PARAM_ALPHA);

admin_externalpage_setup('ldapsync_purgeusers');

$return = $CFG->wwwroot . '/' . $CFG->admin . '/tool/ldapsync/user_bulk_purge.php';

if (empty($SESSION->bulk_users)) {
    redirect($return);
}

$users = $SESSION->bulk_users;
$usertotal = get_users(false);
$usercount = count($users);

$strnever = get_string('never');

echo $OUTPUT->header();

$countries = get_string_manager()->get_list_of_countries(true);

$namefields = get_all_user_name_fields(true);
foreach ($users as $key => $id) {
    $user = $DB->get_record('user', ['id' => $id], 'id, ' . $namefields . ', username, email, country, lastaccess, city');
    $user->fullname = fullname($user, true);
    $user->country = @$countries[$user->country];
    unset($user->firstname);
    unset($user->lastname);
    $users[$key] = $user;
}
unset($countries);

/**
 * Sort function that can sort last access time
 * @package tool_ldapsync
 * @param string|int $a
 * @param string|int $b
 * @return float|int
 */
function sort_compare($a, $b) {
    global $sort, $dir;
    if ($sort == 'lastaccess') {
        $rez = $b->lastaccess - $a->lastaccess;
    } else {
        $rez = strcasecmp(@$a->$sort, @$b->$sort);
    }
    return $dir == 'desc' ? -$rez : $rez;
}
usort($users, 'sort_compare');

$table = new html_table();
$table->width = "95%";
$columns = ['fullname', /*'username', */'email', 'city', 'country', 'lastaccess'];
foreach ($columns as $column) {
    $strtitle = get_string($column);
    if ($sort != $column) {
        $columnicon = '';
        $columndir = 'asc';
    } else {
        $columndir = $dir == 'asc' ? 'desc' : 'asc';
        $icon = 't/down';
        $iconstr = $columndir;
        if ($dir != 'asc') {
            $icon = 't/up';
        }
        $columnicon = ' ' . $OUTPUT->pix_icon($icon, get_string($iconstr));
    }
    $table->head[] = '<a href="user_bulk_display.php?sort=' . $column . '&amp;dir=' . $columndir . '">'
                     . $strtitle . '</a>' . $columnicon;
    $table->align[] = 'left';
}

foreach ($users as $user) {
    $table->data[] = [
        '<a href="' . $CFG->wwwroot . '/user/view.php?id=' . $user->id . '&amp;course=' . SITEID . '">' . $user->fullname . '</a>',
        s($user->email),
        $user->city,
        $user->country,
        $user->lastaccess ? format_time(time() - $user->lastaccess) : $strnever,
    ];
}

echo $OUTPUT->heading("$usercount / $usertotal " . get_string('users'));
echo html_writer::table($table);

echo $OUTPUT->continue_button($return);

echo $OUTPUT->footer();
