<?php
// phpcs:ignoreFile Generic.CodeAnalysis.UselessOverridingMethod.Found - expose protected method for testing

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
 * LDAP Import plugin tests.
 *
 * @package    tool_ldapsync
 * @copyright  2019 onwards, The Regents of the University of California
 * @author     Carson Tam {@email carson.tam@ucsf.edu}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Testable object for the importer to allow testing of protected functions.
 */
class testable_tool_ldapsync_importer extends \tool_ldapsync\importer {
    /**
     * Override function visibility for testing
     * @param array $data
     * @return void
     */
    public function updatemoodleaccounts(array $data) {
        // Change visibility to allow tests to call protected function.
        return parent::updatemoodleaccounts($data);
    }
}
