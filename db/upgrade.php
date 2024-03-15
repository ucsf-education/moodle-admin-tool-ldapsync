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
 * Function to upgrade tool_ldapsync.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool result
 */
function xmldb_tool_ldapsync_upgrade($oldversion) {
    global $CFG, $DB;

    $result = true;

    $dbman = $DB->get_manager();

    if ($oldversion < 2019031802) {
        // Define table tool_ldapsync to be created.
        $table = new xmldb_table('tool_ldapsync');

        // Adding fields to table tool_ldapsync.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('uid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cn', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('createtimestamp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('modifytimestamp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastupdated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table tool_ldapsync.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_username', XMLDB_KEY_FOREIGN_UNIQUE, ['cn'], 'user', ['username']);

        // Conditionally launch create table for tool_ldapsync.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Ldapsync savepoint reached.
        upgrade_plugin_savepoint(true, 2019031802, 'tool', 'ldapsync');
    }

    return $result;
}
