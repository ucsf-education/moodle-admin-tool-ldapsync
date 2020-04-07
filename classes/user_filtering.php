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
 * @package   tool
 * @category  user
 * @copyright Copyright (c) 2020, UCSF Center for Knowledge Management
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ldapsync;

require_once($CFG->dirroot.'/'.$CFG->admin.'/user/lib.php');

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
            case 'timecreated': return new \user_filter_date('timecreated', get_string('createdtime', 'tool_ldapsync'), $advanced, 'timecreated');
            case 'existsinldap':   return new user_filter_yesno('existsinldap', get_string('existsinldap', 'tool_ldapsync'), $advanced, 'existsinldap');
            // case 'additionalldapfilter':    return new \user_filter_text('ldapfilter', get_string('additionalldapfilter', 'tool_ldapsync'), $advanced, 'ldapfilter');
            default:
                return parent::get_field($fieldname, $advanced);
        }
    }

    /**
     * Returns sql where statement based on active user filters
     * @param string $extra sql
     * @param array $params named params (recommended prefix ex)
     * @return array sql string and $params
     */
    public function get_sql_filter($extra='', array $params=null) {
        global $SESSION;

        $sqls = array();
        if ($extra != '') {
            $sqls[] = $extra;
        }
        $params = (array)$params;

        if (!empty($SESSION->user_filtering)) {
            foreach ($SESSION->user_filtering as $fname => $datas) {
                if (!array_key_exists($fname, $this->_fields)) {
                    continue; // Filter not used.
                }
                // Custom: this field is not in database.
                if ($fname == 'existsinldap') {
                    continue;
                }
                $field = $this->_fields[$fname];
                foreach ($datas as $i => $data) {
                    list($s, $p) = $field->get_sql_filter($data);
                    $sqls[] = $s;
                    $params = $params + $p;
                }
            }
        }

        if (empty($sqls)) {
            return array('', array());
        } else {
            $sqls = implode(' AND ', $sqls);
            return array($sqls, $params);
        }
    }
}


/**
 * Generic yes/no filter with radio buttons for integer fields.
 * @copyright Copyright (c) 2019, UCSF Center for Knowledge Management
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_filter_yesno extends \user_filter_yesno {

    /**
     * Returns the condition to be used with SQL
     *
     * @param array $data filter settings
     * @return array sql string and $params
     */
    public function get_sql_filter($data) {
        static $counter = 0;
        $name = 'ex_yesno'.$counter++;

        // Always return an empty array
        return array();

        $value = $data['value'];
        $field = $this->_field;
        if ($value == '') {
            return array();
        }
        return array("$field=:$name", array($name => $value));
    }
}
