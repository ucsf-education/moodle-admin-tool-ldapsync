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
 * LDAP Import plugin tests.
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
 * @copyright  Copyright (c) 2020, UCSF Center for Knowledge Management
 * @author     2020 Carson Tam {@email carson.tam@ucsf.edu}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Testable object for the importer
 */
class Testable_tool_ldapsync_importer_for_purgeusers extends \tool_ldapsync\importer {
    public function getupdatesfromldap($ldap, $ldaptimestamp = null) {
        // Change visibility to allow tests to call protected function.
        return parent::getupdatesfromldap($ldap, $ldaptimestamp);
    }
}
/**
 * Test case for purgeusers
 */
class tool_ldapsync_purgeusers_testcase extends advanced_testcase {
    private $sync = null;
    private $ldapconn = null;

    protected function setUp(): void {
        global $CFG;

        $this->resetAfterTest();

        if (!extension_loaded('ldap')) {
            $this->markTestSkipped('LDAP extension is not loaded.');
        }

        if (
            !defined('TEST_TOOL_LDAPSYNC_HOST_URL') || !defined('TEST_TOOL_LDAPSYNC_BIND_DN')
            || !defined('TEST_TOOL_LDAPSYNC_BIND_PW') || !defined('TEST_TOOL_LDAPSYNC_DOMAIN')
        ) {
            $this->markTestSkipped('External LDAP test server not configured.');
        }

        require_once($CFG->libdir . '/ldaplib.php');

        // Make sure we can connect the server.
        $debuginfo = '';
        if (
            !$connection = ldap_connect_moodle(
                TEST_TOOL_LDAPSYNC_HOST_URL,
                3,
                'rfc2307',
                TEST_TOOL_LDAPSYNC_BIND_DN,
                TEST_TOOL_LDAPSYNC_BIND_PW,
                LDAP_DEREF_NEVER,
                $debuginfo,
                false
            )
        ) {
            $this->markTestSkipped('Can not connect to LDAP test server: ' . $debuginfo);
        }

        $this->ldapConn = $connection;

        // Create new empty test container.
        $topdn = 'dc=moodletest,' . TEST_TOOL_LDAPSYNC_DOMAIN;

        // Let tearDown() handle it.
        $this->recursive_delete($this->ldapConn, TEST_TOOL_LDAPSYNC_DOMAIN, 'dc=moodletest');

        $o = [];
        $o['objectClass'] = ['dcObject', 'organizationalUnit'];
        $o['dc']         = 'moodletest';
        $o['ou']         = 'MOODLETEST';
        if (!ldap_add($this->ldapConn, 'dc=moodletest,' . TEST_AUTH_LDAP_DOMAIN, $o)) {
            $this->markTestSkipped('Can not create test LDAP container.');
        }

        // Create an ou for users.
        $o = [];
        $o['objectClass'] = ['organizationalUnit'];
        $o['ou']          = 'users';
        ldap_add($this->ldapConn, 'ou=' . $o['ou'] . ',' . $topdn, $o);

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
        set_config('user_attribute', 'edupersonprincipalname', 'tool_ldapsync');
        set_config('objectclass', 'ucsfEduPerson', 'tool_ldapsync');

        set_config('field_map_email', 'mail', 'auth_tool_ldapsync');
        set_config('field_updatelocal_email', 'oncreate', 'tool_ldapsync');
        set_config('field_updateremote_email', '0', 'tool_ldapsync');
        set_config('field_lock_email', 'unlocked', 'tool_ldapsync');

        set_config('field_map_firstname', 'ucsfEduPreferredGivenName,givenName', 'auth_tool_ldapsync');
        set_config('field_updatelocal_firstname', 'oncreate', 'tool_ldapsync');
        set_config('field_updateremote_firstname', '0', 'tool_ldapsync');
        set_config('field_lock_firstname', 'unlocked', 'tool_ldapsync');

        set_config('field_map_lastname', 'sn', 'auth_tool_ldapsync');
        set_config('field_updatelocal_lastname', 'oncreate', 'tool_ldapsync');
        set_config('field_updateremote_lastname', '0', 'tool_ldapsync');
        set_config('field_lock_lastname', 'unlocked', 'tool_ldapsync');

        set_config('field_map_idnumber', 'ucsfEduIDNumber', 'auth_tool_ldapsync');
        set_config('field_updatelocal_idnumber', 'oncreate', 'tool_ldapsync');
        set_config('field_updateremote_idnumber', '0', 'tool_ldapsync');
        set_config('field_lock_idnumber', 'unlocked', 'tool_ldapsync');

        $this->sync = new Testable_tool_ldapsync_importer_for_purgeusers($gmtts);

        ob_start();
    }

    protected function tearDown(): void {
        if (!$this->ldapConn) {
            $this->recursive_delete($this->ldapConn, TEST_TOOL_LDAPSYNC_DOMAIN, 'dc=moodletest');
            ldap_close($this->ldapConn);
            $this->ldapConn = null;
        }

        // Use ob_end_flush() if you want to see the output.
        ob_end_clean();
    }

    /**
     * @group ldaptests
     */
    public function testcheckifusersinldap() {
        try {
            $ldap = $this->sync->ldap_connect();
        } catch (Exception $e) {
            $this->markTestIncomplete($e->getMessage());
        }
        $this->assertInstanceOf('LDAP\Connection', $ldap);

        // Create a few users.
        $topdn = 'dc=moodletest,' . TEST_TOOL_LDAPSYNC_DOMAIN;
        for ($i = 1; $i <= 5; $i++) {
            $this->create_ldap_user($this->ldapConn, $topdn, $i);
            $this->assertTrue($this->sync->check_users_in_ldap('00000' . $i . '@ucsf.edu'));
        }

        $this->assertFalse($this->sync->check_users_in_ldap('000006@ucsf.edu'));

        $this->sync->ldap_close();
    }


    /**
     * @group ldaptests
     */
    public function testsetdeletedflagforneverloginusers() {
        global $CFG;

        require_once($CFG->dirroot . '/user/lib.php');

        $user = new stdClass();
        $user->modified = time();
        $user->confirmed = 1;
        $user->auth = 'shibboleth';
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->username = 'testuser';
        if (empty($user->lang)) {
            $user->lang = $CFG->lang;
        }

        $id = user_create_user($user, false);

        $this->assertTrue($id > 0);

        $user->id = $id;
        $this->sync->delete_never_login($user);

        $userslist = user_get_users_by_id([$id]);

        $this->assertEquals(1, $userslist[$id]->deleted);

        // Create user with last login.
        unset($user->id);
        $user->lastlogin = time();

        $id = user_create_user($user, false);

        $this->assertTrue($id > 0);

        $user->id = $id;
        $this->sync->delete_never_login($user);

        $userslist = user_get_users_by_id([$id]);

        $this->assertEquals(0, $userslist[$id]->deleted);
    }


    /**
     * @group ldaptests
     */
    public function testisuserenrolledinanycourse() {
        global $CFG, $DB;
        $this->resetAfterTest(true);

        require_once($CFG->dirroot . '/user/lib.php');

        $user = new stdClass();
        $user->modified = time();
        $user->confirmed = 1;
        $user->auth = 'shibboleth';
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->username = 'testuser';
        if (empty($user->lang)) {
            $user->lang = $CFG->lang;
        }

        $id = user_create_user($user, false);

        $this->assertTrue($id > 0);

        $user->id = $id;

        $defaultcategory = $DB->get_field_select('course_categories', "MIN(id)", "parent=0");

        $course = new stdClass();
        $course->fullname = 'Apu loves Unit Təsts';
        $course->shortname = 'Spread the lŭve';
        $course->idnumber = '123';
        $course->summary = 'Awesome!';
        $course->summaryformat = FORMAT_PLAIN;
        $course->format = 'topics';
        $course->newsitems = 0;
        $course->category = $defaultcategory;
        $original = (array) $course;

        $created = create_course($course);

        $this->assertTrue(empty(enrol_get_users_courses($user->id)));

        $this->assertTrue(enrol_try_internal_enrol($created->id, $user->id));

        $this->assertFalse(empty(enrol_get_users_courses($user->id)));
    }

    protected function create_ldap_user($connection, $topdn, $i) {
        $o = [];
        // Base object class.
        $o['objectClass']   = ['inetOrgPerson', 'organizationalPerson', 'person', 'posixAccount'];
        // Append UCSF specifics.
        $o['objectClass']   = ['inetOrgPerson', 'organizationalPerson', 'person', 'posixAccount', 'eduPerson', 'ucsfEduPerson'];
        $o['cn']            = 'username' . $i;
        $o['sn']            = 'Lastname' . $i;
        $o['givenName']     = 'Firstname' . $i;
        $o['uid']           = $o['cn'];
        $o['uidnumber']     = 2000 + $i;
        $o['gidNumber']     = 1000 + $i;
        $o['homeDirectory'] = '/';
        $o['mail']          = 'user' . $i . '@example.com';
        $o['userPassword']  = 'pass' . $i;
        // UCSF Specifics.
        $o['ucsfEduIDNumber'] = '0200000' . $i . '2';
        $o['eduPersonPrincipalName'] = '00000' . $i . '@ucsf.edu';
        $o['ucsfEduPreferredGivenName'] = 'Preferredname' . $i;
        $o['eduPersonAffiliation'] = 'member'; // E.g. member, staff, faculty.

        ldap_add($connection, 'cn=' . $o['cn'] . ',ou=users,' . $topdn, $o);
    }

    protected function delete_ldap_user($connection, $topdn, $i) {
        ldap_delete($connection, 'cn=username' . $i . ',ou=users,' . $topdn);
    }

    protected function recursive_delete($connection, $dn, $filter) {
        if ($res = ldap_list($connection, $dn, $filter, ['dn'])) {
            $info = ldap_get_entries($connection, $res);
            ldap_free_result($res);
            if ($info['count'] > 0) {
                if ($res = ldap_search($connection, "$filter,$dn", 'cn=*', ['dn'])) {
                    $info = ldap_get_entries($connection, $res);
                    ldap_free_result($res);
                    foreach ($info as $i) {
                        if (isset($i['dn'])) {
                            ldap_delete($connection, $i['dn']);
                        }
                    }
                }
                if ($res = ldap_search($connection, "$filter,$dn", 'ou=*', ['dn'])) {
                    $info = ldap_get_entries($connection, $res);
                    ldap_free_result($res);
                    foreach ($info as $i) {
                        if (isset($i['dn']) && $info[0]['dn'] != $i['dn']) {
                            ldap_delete($connection, $i['dn']);
                        }
                    }
                }
                ldap_delete($connection, "$filter,$dn");
            }
        }
    }
}
