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
 * A scheduled task for LDAP user sync.
 *
 * @package    tool_ldapsync
 * @author     Carson Tam <carson.tam@ucsf.edu>
 * @copyright  Copyright (c) 2019, UCSF Center for Knowledge Management
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace tool_ldapsync\task;

/**
 * A scheduled task class for LDAP user sync.
 *
 */
class import_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('importtask', 'tool_ldapsync');
    }

    /**
     * Run users sync.
     */
    public function execute() {
        global $CFG;

        $sync = new \tool_ldapsync\importer();
        $sync->run();

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
        }

        // TODO: Change 'shibboleth' to auth_type configurable variable
        // if (is_enabled_auth('shibboleth')) {
        //     $auth = get_auth_plugin('shibboleth');
        //     // $auth->sync_users(true);
        // }
    }

}
