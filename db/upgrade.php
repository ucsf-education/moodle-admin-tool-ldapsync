<?php

function xmldb_tool_ldapsync_upgrade($oldversion) {
    global $CFG, $DB;

    $result = TRUE;

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
?>
