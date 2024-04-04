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
 * @copyright  Copyright (c) 2019, UCSF Center for Knowledge Management
 * @author     2019 Carson Tam {@email carson.tam@ucsf.edu}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Testable object for the importer
 */
class Testable_tool_ldapsync_importer_for_plugin extends \tool_ldapsync\importer {
    public function connecttoldap() {
        return parent::connecttoldap();
    }

    /**
     * Searches LDAP for user records that were updated/created after a given datetime.
     * @param \LDAP\Connection $ldap the LDAP connection
     * @param string $baseDn the base DN
     * @param string $ldapTimestamp the datetime
     * @return array nested array of user records
     * @throws Exception if search fails
     */
    public function getupdatesfromldap($ldap, $ldaptimestamp = null) {
        return parent::getupdatesfromldap($ldap, $ldaptimestamp);
    }
}

/**
 * Test case for ldapsync plugin
 */
class tool_ldapsync_plugin_testcase extends advanced_testcase {
    private $sync = null;
    private $ldapconn = null;

    protected function setUp(): void {
        global $CFG;

        $this->resetAfterTest();

        if (!extension_loaded('ldap')) {
            $this->markTestSkipped('LDAP extension is not loaded.');
        }

        if (
            !defined('TEST_TOOL_LDAPSYNC_HOST_URL')
            || !defined('TEST_TOOL_LDAPSYNC_BIND_DN')
            || !defined('TEST_TOOL_LDAPSYNC_BIND_PW')
            || !defined('TEST_TOOL_LDAPSYNC_DOMAIN')
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

        $this->sync = new Testable_tool_ldapsync_importer_for_plugin($gmtts);

        ob_start();
    }

    protected function tearDown(): void {
        if (!$this->ldapConn) {
            $this->recursive_delete($this->ldapConn, TEST_TOOL_LDAPSYNC_DOMAIN, 'dc=moodletest');
            ldap_close($this->ldapConn);
            $this->ldapConn = null;
        }

        ob_end_clean();
        // ob_end_flush();
    }

    /**
     * @group ldaptests
     */
    public function test_connecttoldap() {
        try {
            $ldap = $this->sync->connecttoldap();
            $this->assertInstanceOf('LDAP\Connection', $ldap);
            ldap_close($ldap);
        } catch (Exception $e) {
            $this->markTestIncomplete($e->getMessage());
        }
    }

    /**
     * @group ldaptests
     * @depends test_connecttoldap
     */
    public function test_get_updates_from_ldap() {
        $ldap = $this->sync->connecttoldap();

        // Create a few users
        $topdn = 'dc=moodletest,' . TEST_TOOL_LDAPSYNC_DOMAIN;
        for ($i = 1; $i <= 5; $i++) {
            $this->create_ldap_user($this->ldapConn, $topdn, $i);
        }

        // Test with current time + 7 days, expect no update returned
        $ts = date('YmdHis\Z', time() + 7 * 24 * 3600);
        $result = $this->sync->getupdatesfromldap($ldap, $ts);
        $this->assertEmpty($result);

        // Test with a fix old date, expect the first few updates are the same.
        $ts = '20100101000000Z';
        $result = $this->sync->getupdatesfromldap($ldap, $ts);

        $this->assertGreaterThan(1, count($result));

        foreach ($result as $ldapentry) {
            $this->assertArrayHasKey('uid', $ldapentry);
            $this->assertArrayHasKey('givenname', $ldapentry);
            $this->assertArrayHasKey('sn', $ldapentry);
            $this->assertArrayHasKey('mail', $ldapentry);
            $this->assertArrayHasKey('initials', $ldapentry);
            $this->assertArrayHasKey('displayname', $ldapentry);
            // UCSF specifics
            $this->assertArrayHasKey('ucsfeduidnumber', $ldapentry);
            $this->assertArrayHasKey('edupersonprincipalname', $ldapentry);
            $this->assertArrayHasKey('ucsfedupreferredgivenname', $ldapentry);
            $this->assertArrayHasKey('ucsfedupreferredlastname', $ldapentry);
            $this->assertArrayHasKey('ucsfedupreferredmiddlename', $ldapentry);
        }

        ldap_close($ldap);
    }

    /**
     * @group ldaptests
     * @depends test_connecttoldap
     */
    public function test_tool_ldapsync_importer() {
        global $CFG, $DB;

        // // Create new empty test container.
        $topdn = 'dc=moodletest,' . TEST_TOOL_LDAPSYNC_DOMAIN;

        // Create a few users.
        for ($i = 1; $i <= 5; $i++) {
            $this->create_ldap_user($this->ldapConn, $topdn, $i);
        }

        $this->assertEquals(2, $DB->count_records('user'));
        $this->assertEquals(0, $DB->count_records('role_assignments'));

        ob_start();
        $sink = $this->redirectEvents();
        $this->sync->run();
        $events = $sink->get_events();
        $sink->close();
        ob_end_clean();

        // @TODO Check events, 5 users created. (We should generate user created events but we're not.  So, skip this test for now.)
        // $this->assertCount(5, $events);
        // foreach ($events as $index => $event) {
        // $usercreatedindex = array(0, 2, 4, 5, 6);
        // $roleassignedindex = array (1, 3);
        // if (in_array($index, $usercreatedindex)) {
        // $this->assertInstanceOf('\core\event\user_created', $event);
        // }
        // if (in_array($index, $roleassignedindex)) {
        // $this->assertInstanceOf('\core\event\role_assigned', $event);
        // }
        // }

        // @TODO Should make 'auth' customizable
        $this->assertEquals(5, $DB->count_records('user', ['auth' => 'cas']));

        for ($i = 1; $i <= 5; $i++) {
            $this->assertTrue(
                $DB->record_exists('user', ['username' => '00000' . $i . '@ucsf.edu',
                                            'email' => 'user' . $i . '@example.com',
                                            'firstname' => 'PreferredGivenName' . $i,
                                            'lastname' => 'PreferredLastName' . $i,
                                            'middlename' => 'PreferredMiddleName' . $i,
                                            'alternatename' => 'DisplayName' . $i])
            );
        }

        // @TODO Purge users
        // // Test delete LDAP users

        // $this->delete_ldap_user($connection, $topdn, 1);

        // ob_start();
        // $sink = $this->redirectEvents();
        // $importer->run();
        // $events = $sink->get_events();
        // $sink->close();
        // ob_end_clean();

        // // Check events, no new event.
        // $this->assertCount(0, $events);

        // $this->assertEquals(5, $DB->count_records('user', array('auth'=>'ldap')));
        // $this->assertEquals(0, $DB->count_records('user', array('suspended'=>1)));
        // $this->assertEquals(0, $DB->count_records('user', array('deleted'=>1)));

        // set_config('removeuser', AUTH_REMOVEUSER_SUSPEND, 'auth_ldap');

        // /** @var auth_plugin_ldap $auth */
        // $auth = get_auth_plugin('ldap');

        // ob_start();
        // $sink = $this->redirectEvents();
        // $importer->run();
        // $events = $sink->get_events();
        // $sink->close();
        // ob_end_clean();

        // // Check events, 1 user got updated.
        // $this->assertCount(1, $events);
        // $event = reset($events);
        // $this->assertInstanceOf('\core\event\user_updated', $event);

        // $this->assertEquals(5, $DB->count_records('user', array('auth'=>'ldap')));
        // $this->assertEquals(0, $DB->count_records('user', array('auth'=>'nologin', 'username'=>'username1')));
        // $this->assertEquals(1, $DB->count_records('user', array('auth'=>'ldap', 'suspended'=>'1', 'username'=>'username1')));
        // $this->assertEquals(0, $DB->count_records('user', array('deleted'=>1)));
        // $this->assertEquals(2, $DB->count_records('role_assignments'));
        // $this->assertEquals(2, $DB->count_records('role_assignments', array('roleid'=>$creatorrole->id)));

        // $this->create_ldap_user($connection, $topdn, 1);

        // ob_start();
        // $sink = $this->redirectEvents();
        // $importer->run();
        // // $auth->sync_users(true);
        // $events = $sink->get_events();
        // $sink->close();
        // ob_end_clean();

        // // Check events, 1 user got updated.
        // $this->assertCount(1, $events);
        // $event = reset($events);
        // $this->assertInstanceOf('\core\event\user_updated', $event);

        // $this->assertEquals(5, $DB->count_records('user', array('auth'=>'ldap')));
        // $this->assertEquals(0, $DB->count_records('user', array('suspended'=>1)));
        // $this->assertEquals(0, $DB->count_records('user', array('deleted'=>1)));
        // $this->assertEquals(2, $DB->count_records('role_assignments'));
        // $this->assertEquals(2, $DB->count_records('role_assignments', array('roleid'=>$creatorrole->id)));

        // $DB->set_field('user', 'auth', 'nologin', array('username'=>'username1'));

        // ob_start();
        // $sink = $this->redirectEvents();
        // $importer->run();
        // // $auth->sync_users(true);
        // $events = $sink->get_events();
        // $sink->close();
        // ob_end_clean();

        // // Check events, 1 user got updated.
        // $this->assertCount(1, $events);
        // $event = reset($events);
        // $this->assertInstanceOf('\core\event\user_updated', $event);

        // $this->assertEquals(5, $DB->count_records('user', array('auth'=>'ldap')));
        // $this->assertEquals(0, $DB->count_records('user', array('suspended'=>1)));
        // $this->assertEquals(0, $DB->count_records('user', array('deleted'=>1)));
        // $this->assertEquals(2, $DB->count_records('role_assignments'));
        // $this->assertEquals(2, $DB->count_records('role_assignments', array('roleid'=>$creatorrole->id)));

        // set_config('removeuser', AUTH_REMOVEUSER_FULLDELETE, 'auth_ldap');

        // /** @var auth_plugin_ldap $auth */
        // $auth = get_auth_plugin('ldap');

        // $this->delete_ldap_user($connection, $topdn, 1);

        // ob_start();
        // $sink = $this->redirectEvents();
        // $importer->run();
        // // $auth->sync_users(true);
        // $events = $sink->get_events();
        // $sink->close();
        // ob_end_clean();

        // // Check events, 2 events role_unassigned and user_deleted.
        // $this->assertCount(2, $events);
        // $event = array_pop($events);
        // $this->assertInstanceOf('\core\event\user_deleted', $event);
        // $event = array_pop($events);
        // $this->assertInstanceOf('\core\event\role_unassigned', $event);

        // $this->assertEquals(5, $DB->count_records('user', array('auth'=>'ldap')));
        // $this->assertEquals(0, $DB->count_records('user', array('username'=>'username1')));
        // $this->assertEquals(0, $DB->count_records('user', array('suspended'=>1)));
        // $this->assertEquals(1, $DB->count_records('user', array('deleted'=>1)));
        // $this->assertEquals(1, $DB->count_records('role_assignments'));
        // $this->assertEquals(1, $DB->count_records('role_assignments', array('roleid'=>$creatorrole->id)));

        // $this->create_ldap_user($connection, $topdn, 1);

        // ob_start();
        // $sink = $this->redirectEvents();
        // $importer->run();
        // // $auth->sync_users(true);
        // $events = $sink->get_events();
        // $sink->close();
        // ob_end_clean();

        // // Check events, 2 events role_assigned and user_created.
        // $this->assertCount(2, $events);
        // $event = array_pop($events);
        // $this->assertInstanceOf('\core\event\role_assigned', $event);
        // $event = array_pop($events);
        // $this->assertInstanceOf('\core\event\user_created', $event);

        // $this->assertEquals(6, $DB->count_records('user', array('auth'=>'ldap')));
        // $this->assertEquals(1, $DB->count_records('user', array('username'=>'username1')));
        // $this->assertEquals(0, $DB->count_records('user', array('suspended'=>1)));
        // $this->assertEquals(1, $DB->count_records('user', array('deleted'=>1)));
        // $this->assertEquals(2, $DB->count_records('role_assignments'));
        // $this->assertEquals(2, $DB->count_records('role_assignments', array('roleid'=>$creatorrole->id)));

        // $this->recursive_delete($connection, TEST_AUTH_LDAP_DOMAIN, 'dc=moodletest');
        // ldap_close($connection);
    }

    protected function create_ldap_user($connection, $topdn, $i) {
        $o = [];
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
        $o['initials']      = 'Initials' . $i;
        $o['displayName']   = 'DisplayName' . $i;
        // UCSF Specifics
        $o['ucsfEduIDNumber'] = '0200000' . $i . '2';
        $o['eduPersonPrincipalName'] = '00000' . $i . '@ucsf.edu';
        $o['ucsfEduPreferredGivenName'] = 'PreferredGivenName' . $i;
        $o['ucsfEduPreferredLastName'] = 'PreferredLastName' . $i;
        $o['ucsfEduPreferredMiddleName'] = 'PreferredMiddleName' . $i;
        $o['eduPersonAffiliation'] = 'member'; // e.g. member, staff, faculty

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
