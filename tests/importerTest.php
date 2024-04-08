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
 * LDAP Import tests without an actual LDAP server.
 *
 * NOTE: in order to execute this test you need to set up
 *       OpenLDAP server with core, cosine, nis and internet schemas
 *       and add configuration constants to config.php or phpunit.xml configuration file:
 *
 * define('TEST_AUTH_LDAP_HOST_URL', 'ldap://127.0.0.1');
 * define('TEST_AUTH_LDAP_BIND_DN', 'cn=someuser,dc=example,dc=local');
 * define('TEST_AUTH_LDAP_BIND_PW', 'somepassword');
 * define('TEST_AUTH_LDAP_DOMAIN', 'dc=example,dc=local');
 *
 * @package    tool_ldapsync
 * @copyright  Copyright (c) 2024, UCSF Center for Knowledge Management
 * @author     2024 Carson Tam {@email carson.tam@ucsf.edu}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Testable object for the importer
 */
class Testable_tool_ldapsync_importer extends \tool_ldapsync\importer {
    public function updatemoodleaccounts(array $data) {
        // Change visibility to allow tests to call protected function.
        return parent::updatemoodleaccounts($data);
    }
}
/**
 * Test case for ldapsync importer
 */
class tool_ldapsync_importer_testcase extends advanced_testcase {
    private $sync = null;

    protected function setUp(): void {
        // Create new empty test container.
        $topdn = 'dc=moodletest,' . TEST_TOOL_LDAPSYNC_DOMAIN;

        $gmtts = strtotime('2000-01-01 00:00:00');

        // Configure the plugin a bit.
        set_config('host_url', TEST_TOOL_LDAPSYNC_HOST_URL, 'tool_ldapsync');
        set_config('start_tls', 0, 'tool_ldapsync');
        set_config('ldap_version', 3, 'tool_ldapsync');
        set_config('bind_dn', TEST_TOOL_LDAPSYNC_BIND_DN, 'tool_ldapsync');
        set_config('bind_pw', TEST_TOOL_LDAPSYNC_BIND_PW, 'tool_ldapsync');
        set_config('user_type', 'rfc2307', 'tool_ldapsync');
        set_config('contexts', 'ou=users,' . $topdn, 'tool_ldapsync');
        set_config('opt_deref', LDAP_DEREF_NEVER, 'tool_ldapsync');
        set_config('user_attribute', 'eduPersonPrincipalName', 'tool_ldapsync');
        set_config('objectclass', 'ucsfEduPerson', 'tool_ldapsync');
        set_config('authtype', 'cas', 'tool_ldapsync');

        set_config('field_map_email', 'mail', 'auth_tool_ldapsync');
        set_config('field_updatelocal_email', 'oncreate', 'auth_tool_ldapsync');
        set_config('field_updateremote_email', '0', 'auth_tool_ldapsync');
        set_config('field_lock_email', 'unlocked', 'auth_tool_ldapsync');

        set_config('field_map_firstname', 'ucsfEduPreferredGivenName,givenName', 'auth_tool_ldapsync');
        set_config('field_updatelocal_firstname', 'oncreate', 'auth_tool_ldapsync');
        set_config('field_updateremote_firstname', '0', 'auth_tool_ldapsync');
        set_config('field_lock_firstname', 'unlocked', 'auth_tool_ldapsync');

        set_config('field_map_lastname', 'ucsfEduPreferredLastName,sn', 'auth_tool_ldapsync');
        set_config('field_updatelocal_lastname', 'oncreate', 'auth_tool_ldapsync');
        set_config('field_updateremote_lastname', '0', 'auth_tool_ldapsync');
        set_config('field_lock_lastname', 'unlocked', 'auth_tool_ldapsync');

        set_config('field_map_middlename', 'ucsfEduPreferredMiddleName,initials', 'auth_tool_ldapsync');
        set_config('field_updatelocal_middlename', 'oncreate', 'auth_tool_ldapsync');
        set_config('field_updateremote_middlename', '0', 'auth_tool_ldapsync');
        set_config('field_lock_middlename', 'unlocked', 'auth_tool_ldapsync');

        set_config('field_map_alternatename', 'displayName', 'auth_tool_ldapsync');
        set_config('field_updatelocal_alternatename', 'oncreate', 'auth_tool_ldapsync');
        set_config('field_updateremote_alternatename', '0', 'auth_tool_ldapsync');
        set_config('field_lock_alternatename', 'unlocked', 'auth_tool_ldapsync');

        set_config('field_map_idnumber', 'ucsfEduIDNumber', 'auth_tool_ldapsync');
        set_config('field_updatelocal_idnumber', 'oncreate', 'auth_tool_ldapsync');
        set_config('field_updateremote_idnumber', '0', 'auth_tool_ldapsync');
        set_config('field_lock_idnumber', 'unlocked', 'auth_tool_ldapsync');

        $this->sync = new Testable_tool_ldapsync_importer($gmtts);

        ob_start();
    }

    protected function tearDown(): void {
        // Use ob_end_flush() to see output.
        ob_end_clean();
    }

    /**
     * @dataProvider ldapsync_data_provider
     */
    public function test_adding_new_users(array $ldapuser, array $expected) {
        global $DB;
        $this->resetAfterTest(true);

        $data = [$ldapuser];

        $expectedfinalcount = $DB->count_records('user') + count($data);
        $this->sync->updatemoodleaccounts($data);

        $finalcount = $DB->count_records('user');
        $this->assertEquals($expectedfinalcount, $finalcount);

        $record = $DB->get_record('user', ['idnumber' => $ldapuser['ucsfeduidnumber']]);
        foreach ($expected as $moodle => $ldap) {
            $this->assertEquals($ldapuser[$ldap], $record->$moodle);
        }

        // We want forum tracking 'on' to be the default.
        $this->assertEquals('1', $record->trackforums);
    }

    /**
     * Test updating an existing account with apostrophes and dashes in last name
     */
    public function test_update_existing_account_with_apostrophes_and_dashes() {
        global $DB;
        $this->resetAfterTest(true);

        $data = [
                    [
                    "uid" => "1",
                    "givenname" => "Jane",
                    "sn" => "Doe",
                    "mail" => "Jane.Doe@example.com",
                    "ucsfeduidnumber" => "011234569",
                    "edupersonprincipalname" => "123456@example.com",
                    ],
                ];

        $expectedfinalcount = $DB->count_records('user') + count($data);

        $this->sync->updatemoodleaccounts($data);
        $finalcount = $DB->count_records('user');

        $this->assertEquals($expectedfinalcount, $finalcount);

        foreach ($data as $user) {
            $record = $DB->get_record('user', ['idnumber' => $user['ucsfeduidnumber']]);

            if ($user['ucsfedupreferredgivenname'] === '') {
                $this->assertEquals($user['givenname'], $record->firstname);
            } else {
                $this->assertEquals($user['ucsfedupreferredgivenname'], $record->firstname);
            }

            $this->assertEquals($user['sn'], $record->lastname);
            $this->assertEquals($user['mail'], $record->email);
        }

        // Test update existing record with dashes.
        $data = [ [
                    "uid" => "1",
                    "givenname" => "Jane",
                    "sn" => "Doe-O'Reilly",
                    "mail" => "Jane.Doe-O'Reilly@example.com",
                    "ucsfeduidnumber" => "011234569",
                    "edupersonprincipalname" => "123456@example.com",
                    ] ];
        $expectedfinalcount = $DB->count_records('user');
        $this->sync->updatemoodleaccounts($data);
        $finalcount = $DB->count_records('user');
        $this->assertEquals($expectedfinalcount, $finalcount);

        foreach ($data as $user) {
            $record = $DB->get_record('user', ['idnumber' => $user['ucsfeduidnumber']]);
            $this->assertEquals($user['givenname'], $record->firstname);
            $this->assertEquals($user['sn'], $record->lastname);
            $this->assertEquals($user['mail'], $record->email);
            $this->assertEquals($user['edupersonprincipalname'], $record->username);
        }
    }

    public function test_user_with_empty_eppn_should_be_skipped() {
        global $DB;
        $this->resetAfterTest(true);

        $data = [ [
                    "uid" => "1",
                    "givenname" => "Jane",
                    "sn" => "Doe",
                    "initials" => "",
                    "mail" => "Jane.Doe@example.com",
                    "ucsfeduidnumber" => "011234569",
                    "edupersonprincipalname" => "",
                    "ucsfedupreferredgivenname" => "",
                    "ucsfedupreferredlastname" => "",
                    "ucsfedupreferredmiddlename" => "",
                    "displayname" => "",
                    ],
                    [
                    "uid" => "2",
                    "givenname" => "John",
                    "sn" => "Doe",
                    "initials" => "",
                    "mail" => "John.Doe@example.com",
                    "ucsfeduidnumber" => "122345670",
                    "edupersonprincipalname" => "",
                    "ucsfedupreferredgivenname" => "",
                    "ucsfedupreferredlastname" => "",
                    "ucsfedupreferredmiddlename" => "",
                    "displayname" => "",
                    ],
                    [
                    "uid" => "3",
                    "givenname" => "Joseph",
                    "sn" => "O'Reilly",
                    "initials" => "",
                    "mail" => "Joseph.O'Reilly@example.com",
                    "ucsfeduidnumber" => "023456787",
                    "edupersonprincipalname" => "345678@example.com",
                    "ucsfedupreferredgivenname" => "Joe",
                    "ucsfedupreferredlastname" => "",
                    "ucsfedupreferredmiddlename" => "",
                    "displayname" => "",
                    ],
                ];

        $expectedfinalcount = $DB->count_records('user') + 1;

        $this->sync->updatemoodleaccounts($data);

        $finalcount = $DB->count_records('user');

        $this->assertEquals($expectedfinalcount, $finalcount);
    }

    public function test_skipping_all_users_will_not_generate_error() {
        global $DB;
        $this->resetAfterTest(true);

        $data = [ [
                    "uid" => "1",
                    "givenname" => "Jane",
                    "sn" => "Doe",
                    "initials" => "",
                    "mail" => "Jane.Doe@example.com",
                    "ucsfeduidnumber" => "011234569",
                    "edupersonprincipalname" => "",
                    "ucsfedupreferredgivenname" => "",
                    "ucsfedupreferredlastname" => "",
                    "ucsfedupreferredmiddlename" => "",
                    "displayname" => "",
                    ],
                    [
                    "uid" => "2",
                    "givenname" => "John",
                    "sn" => "Doe",
                    "initials" => "",
                    "mail" => "John.Doe@example.com",
                    "ucsfeduidnumber" => "122345670",
                    "edupersonprincipalname" => "",
                    "ucsfedupreferredgivenname" => "",
                    "ucsfedupreferredlastname" => "",
                    "ucsfedupreferredmiddlename" => "",
                    "displayname" => "",
                    ],
                    [
                    "uid" => "3",
                    "givenname" => "Joseph",
                    "sn" => "O'Reilly",
                    "initials" => "",
                    "mail" => "Joseph.O'Reilly@example.com",
                    "ucsfeduidnumber" => "023456787",
                    "edupersonprincipalname" => "",
                    "ucsfedupreferredgivenname" => "Joe",
                    "ucsfedupreferredlastname" => "",
                    "ucsfedupreferredmiddlename" => "",
                    "displayname" => "",
                    ],
                ];

        $expectedfinalcount = $DB->count_records('user');

        $this->sync->updatemoodleaccounts($data);

        $finalcount = $DB->count_records('user');

        $this->assertEquals($expectedfinalcount, $finalcount);
    }

    /**
     * Test data set
     *
     * The format for these data is:
     * [ 'Test case description' => [
     *      [LDAP data set],
     *      [Expected Moodle field to match] => [Expected LDAP field to match] ]
     * ]
     */
    public function ldapsync_data_provider() {
        return [
            'Simple case' => [
                [
                    "uid" => "1",
                    "givenname" => "Jane",
                    "sn" => "Doe",
                    "mail" => "Jane.Doe@example.com",
                    "ucsfeduidnumber" => "011234569",
                    "edupersonprincipalname" => "123456@example.com",
                    "displayname" => "Jane Doe",
                ],
                [
                    "firstname" => "givenname",
                    "lastname" => "sn",
                    "email" => "mail",
                    "idnumber" => "ucsfeduidnumber",
                    "username" => "edupersonprincipalname",
                    "alternatename" => "displayname",
                ],
            ],
            'Simple case with initials' => [
                [
                    "uid" => "2",
                    "givenname" => "John",
                    "initials" => "A",
                    "sn" => "Doe",
                    "mail" => "John.Doe@example.com",
                    "ucsfeduidnumber" => "122345670",
                    "edupersonprincipalname" => "234567@example.com",
                    "displayname" => "Johnny Amos Doe",
                ],
                [
                    "firstname" => "givenname",
                    "middlename" => "initials",
                    "lastname" => "sn",
                    "email" => "mail",
                    "idnumber" => "ucsfeduidnumber",
                    "username" => "edupersonprincipalname",
                    "alternatename" => "displayname",
                ],
            ],
            'Last name with apostrophes' => [
                [
                    "uid" => "3",
                    "givenname" => "Joseph",
                    "sn" => "O'Reilly",
                    "mail" => "Joseph.O'Reilly@example.com",
                    "ucsfeduidnumber" => "023456787",
                    "edupersonprincipalname" => "345678@example.com",
                    "displayname" => "Joe Rily",
                ],
                [
                    "firstname" => "givenname",
                    "lastname" => "sn",
                    "email" => "mail",
                    "idnumber" => "ucsfeduidnumber",
                    "username" => "edupersonprincipalname",
                    "alternatename" => "displayname",
                ],
            ],
            'First name with dash' => [
                [
                    "uid" => "4",
                    "givenname" => "Mary-Ann",
                    "sn" => "O'Reilly",
                    "mail" => "Mary-Ann.O'Reilly@example.com",
                    "ucsfeduidnumber" => "024567897",
                    "edupersonprincipalname" => "456789@example.com",
                    "displayname" => "Maryann O'Reilly",
                ],
                [
                    "firstname" => "givenname",
                    "lastname" => "sn",
                    "email" => "mail",
                    "idnumber" => "ucsfeduidnumber",
                    "username" => "edupersonprincipalname",
                    "alternatename" => "displayname",
                ],
            ],
            'Use preferred first name instead of givenname' => [
                [
                    "uid" => "5",
                    "givenname" => "Jim",
                    "sn" => "Doe",
                    "mail" => "Jim.Doe@example.com",
                    "ucsfeduidnumber" => "025678909",
                    "edupersonprincipalname" => "567890@example.com",
                    "ucsfedupreferredgivenname" => "Jimmy",
                    "displayname" => "Jimmy Doe",
                ],
                [
                    "firstname" => "ucsfedupreferredgivenname",
                    "lastname" => "sn",
                    "email" => "mail",
                    "idnumber" => "ucsfeduidnumber",
                    "username" => "edupersonprincipalname",
                    "alternatename" => "displayname",
                ],
            ],
            'Use preferred names instead of givenname, initials, and sn' => [
                [
                    "uid" => "6",
                    "givenname" => "Jackson",
                    "initials" => "Michael",
                    "sn" => "Doe",
                    "mail" => "Jackson.Doe@example.com",
                    "ucsfeduidnumber" => "026789019",
                    "edupersonprincipalname" => "678901@example.com",
                    "ucsfedupreferredgivenname" => "Jack",
                    "ucsfedupreferredmiddlename" => "Mike",
                    "ucsfedupreferredlastname" => "Dee",
                    "displayname" => "Mikey Dee",
                ],
                [
                    "firstname" => "ucsfedupreferredgivenname",
                    "middlename" => "ucsfedupreferredmiddlename",
                    "lastname" => "ucsfedupreferredlastname",
                    "email" => "mail",
                    "idnumber" => "ucsfeduidnumber",
                    "username" => "edupersonprincipalname",
                    "alternatename" => "displayname",
                ],
            ],
        ];
    }
}
