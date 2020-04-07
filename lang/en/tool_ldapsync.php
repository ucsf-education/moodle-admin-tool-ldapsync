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
 * Strings for component 'tool_ldapsync', language 'en'
 *
 * @package    tool
 * @subpackage ldapsync
 * @author     Carson Tam <carson.tam@ucsf.edu>
 * @copyright  Copyright (c) 2020, UCSF Center for Knowledge Management
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['additionalldapfilter'] = 'Additional LDAP filter';
$string['authtype'] = 'Auth type';
$string['authtype_description'] = 'Select the authentication type for the imported accounts.';
$string['configuretask'] = 'Configure scheduled task';
$string['createdtime'] = 'Created time';
$string['existsinldap'] = 'Exists on LDAP server';
$string['importtask'] = 'Import LDAP users';
$string['ldap_noextension'] = '<em>The PHP LDAP module does not seem to be present. Please ensure it is installed and enabled if you want to use this LDAP Sync plugin.</em>';
$string['ldapsync_description'] = 'This method imports user accounts from an external LDAP server.
                                  If the given username and password are valid, Moodle creates a new user
                                  entry in its database. This module can read user attributes from LDAP and prefill
                                  wanted fields in Moodle.';
$string['opt_deref'] = 'Determines how aliases are handled during search. Select one of the following values: "No" (LDAP_DEREF_NEVER) or "Yes" (LDAP_DEREF_ALWAYS)';
$string['opt_deref_key'] = 'Dereference aliases';
$string['pluginname'] = 'Import users from LDAP';
$string['privacy:metadata'] = 'This plugin import user data from LDAP.';
$string['purgeusers'] = 'Review/Purge user accounts';
