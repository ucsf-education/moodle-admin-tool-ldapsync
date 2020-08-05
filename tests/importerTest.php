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
 * @category   phpunit
 * @copyright  Copyright (c) 2019, UCSF Center for Knowledge Management
 * @author     2019 Carson Tam {@email carson.tam@ucsf.edu}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class Testable_tool_ldapsync_importer extends \tool_ldapsync\importer
{
    public function updateMoodleAccounts(array $data)
    {
        return $this->_updateMoodleAccounts($data);
    }
}

class tool_ldapsync_importer_testcase extends advanced_testcase
{
    private $sync = null;

    protected function setUp()
    {
        $gmtTs = strtotime('2000-01-01 00:00:00');
        $this->sync = new Testable_tool_ldapsync_importer($gmtTs);

        ob_start();
    }

    protected function tearDown()
    {
        ob_end_clean();
    }

    public function testUpdateMoodleAccountsWithApostrophesAndDashes()
    {
        global $DB;
        $this->resetAfterTest(true);

        $data = array( array(
                             "uid" => "1",
                             "givenname" => "Jane",
                             "sn" => "Doe",
                             "mail" => "Jane.Doe@example.com",
                             "ucsfeduidnumber" => "011234569",
                             "edupersonprincipalname" => "123456@example.com",
                             "ucsfedupreferredgivenname" => ""
                             ),
                       array(
                             "uid" => "2",
                             "givenname" => "John",
                             "sn" => "Doe",
                             "mail" => "John.Doe@example.com",
                             "ucsfeduidnumber" => "122345670",
                             "edupersonprincipalname" => "234567@example.com",
                             "ucsfedupreferredgivenname" => ""
                             ),
                       array(
                             "uid" => "3",
                             "givenname" => "Joseph",
                             "sn" => "O'Reilly",
                             "mail" => "Joseph.O'Reilly@example.com",
                             "ucsfeduidnumber" => "023456787",
                             "edupersonprincipalname" => "345678@example.com",
                             "ucsfedupreferredgivenname" => "Joe"
                             )
                       );

        $expectedFinalCount = $DB->count_records('user') + count($data);

        $this->sync->updateMoodleAccounts($data);
        $finalCount = $DB->count_records('user');

        $this->assertEquals($expectedFinalCount, $finalCount);

        foreach ($data as $user) {
            $record = $DB->get_record('user', array('idnumber' => $user['ucsfeduidnumber']));

            if ($user['ucsfedupreferredgivenname'] === '')
                $this->assertEquals($user['givenname'], $record->firstname);
            else
                $this->assertEquals($user['ucsfedupreferredgivenname'], $record->firstname);

            $this->assertEquals($user['sn'], $record->lastname);
            $this->assertEquals($user['mail'], $record->email);
        }

        // Test update existing record with dashes
        $data = array( array(
                             "uid" => "1",
                             "givenname" => "Jane",
                             "sn" => "Doe-O'Reilly",
                             "mail" => "Jane.Doe-O'Reilly@example.com",
                             "ucsfeduidnumber" => "011234569",
                             "edupersonprincipalname" => "123456@example.com",
                             "ucsfedupreferredgivenname" => ""
                             ) );
        $expectedFinalCount = $DB->count_records('user');
        $this->sync->updateMoodleAccounts($data);
        $finalCount = $DB->count_records('user');
        $this->assertEquals($expectedFinalCount, $finalCount);

        foreach ($data as $user) {
            $record = $DB->get_record('user', array('idnumber' => $user['ucsfeduidnumber']));
            $this->assertEquals($user['givenname'], $record->firstname);
            $this->assertEquals($user['sn'], $record->lastname);
            $this->assertEquals($user['mail'], $record->email);
        }
    }

    public function testAddingNewUserWithOurDefaultValuesSet()
    {
        global $DB;
        $this->resetAfterTest(true);

        $data = array( array( "uid" => 1,
                              "givenname" => "John",
                              "sn" => "Doe",
                              "mail" => "John.Doe@example.com",
                              "ucsfeduidnumber" => "991234569",
                              "edupersonprincipalname" => "123456@example.com",
                              "ucsfedupreferredgivenname" => "") );

        $expectedFinalCount = $DB->count_records('user') + count($data);

        $this->sync->updateMoodleAccounts($data);

        $finalCount = $DB->count_records('user');

        $this->assertEquals($expectedFinalCount, $finalCount);

        foreach ($data as $user) {
            $record = $DB->get_record('user', array('idnumber' => $user['ucsfeduidnumber']));
            $this->assertEquals($user['givenname'], $record->firstname);
            $this->assertEquals($user['sn'], $record->lastname);
            $this->assertEquals($user['mail'], $record->email);
            // We want forum tracking 'on' to be the default.
            $this->assertEquals('1', $record->trackforums);
        }
    }

    public function testUserWithEmptyEPPNShouldBeSkipped()
    {
        global $DB;
        $this->resetAfterTest(true);

        $data = array( array(
                             "uid" => "1",
                             "givenname" => "Jane",
                             "sn" => "Doe",
                             "mail" => "Jane.Doe@example.com",
                             "ucsfeduidnumber" => "011234569",
                             "edupersonprincipalname" => "",
                             "ucsfedupreferredgivenname" => ""
                             ),
                       array(
                             "uid" => "2",
                             "givenname" => "John",
                             "sn" => "Doe",
                             "mail" => "John.Doe@example.com",
                             "ucsfeduidnumber" => "122345670",
                             "edupersonprincipalname" => "",
                             "ucsfedupreferredgivenname" => ""
                             ),
                       array(
                             "uid" => "3",
                             "givenname" => "Joseph",
                             "sn" => "O'Reilly",
                             "mail" => "Joseph.O'Reilly@example.com",
                             "ucsfeduidnumber" => "023456787",
                             "edupersonprincipalname" => "345678@example.com",
                             "ucsfedupreferredgivenname" => "Joe"
                             )
                       );

        $expectedFinalCount = $DB->count_records('user') + 1;

        $this->sync->updateMoodleAccounts($data);

        $finalCount = $DB->count_records('user');

        $this->assertEquals($expectedFinalCount, $finalCount);

    }

    public function testSkippingAllUsersWillNotGenerateError()
    {
        global $DB;
        $this->resetAfterTest(true);

        $data = array( array(
                             "uid" => "1",
                             "givenname" => "Jane",
                             "sn" => "Doe",
                             "mail" => "Jane.Doe@example.com",
                             "ucsfeduidnumber" => "011234569",
                             "edupersonprincipalname" => "",
                             "ucsfedupreferredgivenname" => ""
                             ),
                       array(
                             "uid" => "2",
                             "givenname" => "John",
                             "sn" => "Doe",
                             "mail" => "John.Doe@example.com",
                             "ucsfeduidnumber" => "122345670",
                             "edupersonprincipalname" => "",
                             "ucsfedupreferredgivenname" => ""
                             ),
                       array(
                             "uid" => "3",
                             "givenname" => "Joseph",
                             "sn" => "O'Reilly",
                             "mail" => "Joseph.O'Reilly@example.com",
                             "ucsfeduidnumber" => "023456787",
                             "edupersonprincipalname" => "",
                             "ucsfedupreferredgivenname" => "Joe"
                             )
                       );

        $expectedFinalCount = $DB->count_records('user');

        $this->sync->updateMoodleAccounts($data);

        $finalCount = $DB->count_records('user');

        $this->assertEquals($expectedFinalCount, $finalCount);

    }

}
