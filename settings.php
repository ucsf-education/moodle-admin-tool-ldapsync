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
 * Import LDAP account into Shibboleth
 *
 * @package    tool_ldapsync
 * @copyright  Copyright (c) 2020, UCSF Center for Knowledge Management
 * @author     2020 Carson Tam {@email carson.tam@ucsf.edu}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $ADMIN->add('accounts', new admin_category('ldapsync', get_string('pluginname', 'tool_ldapsync')));

    // LDAP settingpage.
    $settings = new admin_settingpage('ldapsync_settings', get_string('settings'));

    if ($ADMIN->fulltree) {
        if (!function_exists('ldap_connect')) {
            $settings->add(new admin_setting_heading('ldap_noextension', '', get_string('ldap_noextension', 'tool_ldapsync')));
        } else {
            // We use a couple of custom admin settings since we need to massage the data before it is inserted into the DB.
            require_once($CFG->dirroot . '/auth/ldap/classes/admin_setting_special_lowercase_configtext.php');
            require_once($CFG->dirroot . '/auth/ldap/classes/admin_setting_special_contexts_configtext.php');
            require_once($CFG->dirroot . '/auth/ldap/classes/admin_setting_special_ntlm_configtext.php');

            // We need to use some of the Moodle LDAP constants / functions to create the list of options.
            require_once($CFG->dirroot . '/auth/ldap/auth.php');

            // Introductory explanation.
            $settings->add(new admin_setting_heading(
                'tool_ldapsync/pluginname',
                '',
                new lang_string('ldapsync_description', 'tool_ldapsync')
            ));

            // Target authtype setting, e.g. manual, shibboleth
            $enabledauth = ['manual' => 'manual'];
            $moreauths = $DB->get_field('config', 'value', ['name' => 'auth']);
            if (!empty($moreauths)) {
                $auths = explode(',', $moreauths);
                foreach ($auths as $auth) {
                    $enabledauth[$auth] = $auth;
                }
            }

            $settings->add(new admin_setting_configselect(
                'tool_ldapsync/authtype',
                new lang_string('authtype', 'tool_ldapsync'),
                new lang_string('authtype_description', 'tool_ldapsync'),
                \tool_ldapsync\importer::MOODLE_AUTH_ADAPTER,
                $enabledauth
            ));

            // LDAP server settings.
            $settings->add(new admin_setting_heading(
                'tool_ldapsync/ldapserversettings',
                new lang_string('auth_ldap_server_settings', 'auth_ldap'),
                ''
            ));

            // Host.
            $settings->add(new admin_setting_configtext(
                'tool_ldapsync/host_url',
                get_string('auth_ldap_host_url_key', 'auth_ldap'),
                get_string('auth_ldap_host_url', 'auth_ldap'),
                '',
                PARAM_RAW_TRIMMED
            ));

            // Version.
            $versions = [];
            $versions[2] = '2';
            $versions[3] = '3';
            $settings->add(new admin_setting_configselect(
                'tool_ldapsync/ldap_version',
                new lang_string('auth_ldap_version_key', 'auth_ldap'),
                new lang_string('auth_ldap_version', 'auth_ldap'),
                3,
                $versions
            ));

            // Start TLS.
            $yesno = [
                new lang_string('no'),
                new lang_string('yes'),
            ];
            $settings->add(new admin_setting_configselect(
                'tool_ldapsync/start_tls',
                new lang_string('start_tls_key', 'auth_ldap'),
                new lang_string('start_tls', 'auth_ldap'),
                0,
                $yesno
            ));


            // Encoding.
            $settings->add(new admin_setting_configtext(
                'tool_ldapsync/ldapencoding',
                get_string('auth_ldap_ldap_encoding_key', 'auth_ldap'),
                get_string('auth_ldap_ldap_encoding', 'auth_ldap'),
                'utf-8',
                PARAM_RAW_TRIMMED
            ));

            // Page Size. (Hide if not available).
            $settings->add(new admin_setting_configtext(
                'tool_ldapsync/pagesize',
                get_string('pagesize_key', 'auth_ldap'),
                get_string('pagesize', 'auth_ldap'),
                '250',
                PARAM_INT
            ));

            // Bind settings.
            $settings->add(new admin_setting_heading(
                'tool_ldapsync/ldapbindsettings',
                new lang_string('auth_ldap_bind_settings', 'auth_ldap'),
                ''
            ));

            // User ID.
            $settings->add(new admin_setting_configtext(
                'tool_ldapsync/bind_dn',
                get_string('auth_ldap_bind_dn_key', 'auth_ldap'),
                get_string('auth_ldap_bind_dn', 'auth_ldap'),
                '',
                PARAM_RAW_TRIMMED
            ));

            // Password.
            $settings->add(new admin_setting_configpasswordunmask(
                'tool_ldapsync/bind_pw',
                get_string('auth_ldap_bind_pw_key', 'auth_ldap'),
                get_string('auth_ldap_bind_pw', 'auth_ldap'),
                ''
            ));

            // User Lookup settings.
            $settings->add(new admin_setting_heading(
                'tool_ldapsync/ldapuserlookup',
                new lang_string('auth_ldap_user_settings', 'auth_ldap'),
                ''
            ));

            // User Type.
            $settings->add(new admin_setting_configselect(
                'tool_ldapsync/user_type',
                new lang_string('auth_ldap_user_type_key', 'auth_ldap'),
                new lang_string('auth_ldap_user_type', 'auth_ldap'),
                'default',
                ldap_supported_usertypes()
            ));

            // Contexts.
            $settings->add(new auth_ldap_admin_setting_special_contexts_configtext(
                'tool_ldapsync/contexts',
                get_string('auth_ldap_contexts_key', 'auth_ldap'),
                get_string('auth_ldap_contexts', 'auth_ldap'),
                '',
                PARAM_RAW_TRIMMED
            ));

            // Search subcontexts.
            $settings->add(new admin_setting_configselect(
                'tool_ldapsync/search_sub',
                new lang_string('auth_ldap_search_sub_key', 'auth_ldap'),
                new lang_string('auth_ldap_search_sub', 'auth_ldap'),
                0,
                $yesno
            ));

            // Dereference aliases.
            $optderef = [];
            $optderef[LDAP_DEREF_NEVER] = get_string('no');
            $optderef[LDAP_DEREF_ALWAYS] = get_string('yes');

            $settings->add(new admin_setting_configselect(
                'tool_ldapsync/opt_deref',
                new lang_string('opt_deref_key', 'tool_ldapsync'),
                new lang_string('opt_deref', 'tool_ldapsync'),
                LDAP_DEREF_NEVER,
                $optderef
            ));

            // User attribute.
            $settings->add(new auth_ldap_admin_setting_special_lowercase_configtext(
                'tool_ldapsync/user_attribute',
                get_string('auth_ldap_user_attribute_key', 'auth_ldap'),
                get_string('auth_ldap_user_attribute', 'auth_ldap'),
                '',
                PARAM_RAW
            ));

            // Suspended attribute.
            $settings->add(new auth_ldap_admin_setting_special_lowercase_configtext(
                'tool_ldapsync/suspended_attribute',
                get_string('auth_ldap_suspended_attribute_key', 'auth_ldap'),
                get_string('auth_ldap_suspended_attribute', 'auth_ldap'),
                '',
                PARAM_RAW
            ));

            // Member attribute.
            $settings->add(new auth_ldap_admin_setting_special_lowercase_configtext(
                'tool_ldapsync/memberattribute',
                get_string('auth_ldap_memberattribute_key', 'auth_ldap'),
                get_string('auth_ldap_memberattribute', 'auth_ldap'),
                '',
                PARAM_RAW
            ));

            // Member attribute uses dn.
            $settings->add(new admin_setting_configtext(
                'tool_ldapsync/memberattribute_isdn',
                get_string('auth_ldap_memberattribute_isdn_key', 'auth_ldap'),
                get_string('auth_ldap_memberattribute_isdn', 'auth_ldap'),
                '',
                PARAM_RAW
            ));

            // Object class.
            $settings->add(new admin_setting_configtext(
                'tool_ldapsync/objectclass',
                get_string('auth_ldap_objectclass_key', 'auth_ldap'),
                get_string('auth_ldap_objectclass', 'auth_ldap'),
                '',
                PARAM_RAW_TRIMMED
            ));

            // User Account Sync.
            $settings->add(new admin_setting_heading(
                'tool_ldapsync/syncusers',
                new lang_string('auth_sync_script', 'auth'),
                ''
            ));

            // Remove external user.
            $deleteopt = [];
            $deleteopt[AUTH_REMOVEUSER_KEEP] = get_string('auth_remove_keep', 'auth');
            $deleteopt[AUTH_REMOVEUSER_SUSPEND] = get_string('auth_remove_suspend', 'auth');
            $deleteopt[AUTH_REMOVEUSER_FULLDELETE] = get_string('auth_remove_delete', 'auth');

            $settings->add(new admin_setting_configselect(
                'tool_ldapsync/removeuser',
                new lang_string('auth_remove_user_key', 'auth'),
                new lang_string('auth_remove_user', 'auth'),
                AUTH_REMOVEUSER_KEEP,
                $deleteopt
            ));

            // Sync Suspension.
            // TODO: What is suspension status?  Is it an attribute in LDAP?
            $settings->add(new admin_setting_configselect(
                'tool_ldapsync/sync_suspended',
                new lang_string('auth_sync_suspended_key', 'auth'),
                new lang_string('auth_sync_suspended', 'auth'),
                0,
                $yesno
            ));
        }

        // Display locking / mapping of profile fields.
        $authplugin = get_auth_plugin('ldap');
        $help  = get_string('auth_ldapextrafields', 'auth_ldap');
        $help .= get_string('auth_updatelocal_expl', 'auth');
        $help .= get_string('auth_fieldlock_expl', 'auth');
        $help .= '<hr />';
        $help .= get_string('auth_updateremote_ldap', 'auth');
        // Using 'display_auth_lock_options' will create config variables with 'auth_' prefixes, i.e. 'auth_tool_ldapsync' instead of 'tool_ldapsync'.
        display_auth_lock_options(
            $settings,
            'tool_ldapsync',
            $authplugin->userfields,
            $help,
            true,
            false,
            $authplugin->get_custom_user_profile_fields()
        );
    }

    $ADMIN->add('ldapsync', $settings);
    $ADMIN->add('ldapsync', new admin_externalpage(
        'ldapsync_testsettings',
        'Test Settings',
        "$CFG->wwwroot/$CFG->admin/tool/ldapsync/testsettings.php"
    ));

    $ADMIN->add('ldapsync', new admin_externalpage(
        'ldapsync_configuretask',
        get_string('configuretask', 'tool_ldapsync'),
        "$CFG->wwwroot/$CFG->admin/tool/task/scheduledtasks.php?action=edit&task=tool_ldapsync%5Ctask%5Cimport_task"
    ));

    $ADMIN->add('ldapsync', new admin_externalpage(
        'ldapsync_purgeusers',
        get_string('purgeusers', 'tool_ldapsync'),
        "$CFG->wwwroot/$CFG->admin/tool/ldapsync/user.php",
        ['moodle/user:delete']
    ));
}
