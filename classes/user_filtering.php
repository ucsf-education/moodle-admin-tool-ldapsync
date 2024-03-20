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
 * This file contains the User Filter API.
 *
 * @package   tool_ldapsync
 * @copyright Copyright (c) 2020, UCSF Center for Knowledge Management
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ldapsync;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/' . $CFG->admin . '/user/lib.php');

/**
 * Inherits user_filtering to add new fields to filter
 */
class user_filtering extends \user_filtering {
    /**
     * Creates known user filter if present
     * @param string $fieldname
     * @param boolean $advanced
     * @return object filter
     */
    public function get_field($fieldname, $advanced) {
        global $USER, $CFG, $DB, $SITE;

        switch ($fieldname) {
            case 'timecreated':
                return new \user_filter_date('timecreated', get_string('createdtime', 'tool_ldapsync'), $advanced, 'timecreated');
            case 'activeonldap':
                return new user_filter_activeonldap('activeonldap', get_string('activeonldap', 'tool_ldapsync'), $advanced, 'activeonldap');
            // case 'additionalldapfilter':    return new \user_filter_text('ldapfilter', get_string('additionalldapfilter', 'tool_ldapsync'), $advanced, 'ldapfilter');
            default:
                return parent::get_field($fieldname, $advanced);
        }
    }
}


/**
 * Generic yes/no filter with radio buttons for integer fields.
 * @copyright Copyright (c) 2019, UCSF Center for Knowledge Management
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_filter_activeonldap extends \user_filter_yesno {
    private $_ldapuserlist = null;

    /**
     * Returns the condition to be used with SQL
     *
     * @param array $data filter settings
     * @return array sql string and $params
     */
    public function get_sql_filter($data) {

        $value = $data['value'];
        $not = $value ? '' : 'NOT';

        return ["username $not IN ( SELECT cn FROM {tool_ldapsync} )", []];
    }
}
