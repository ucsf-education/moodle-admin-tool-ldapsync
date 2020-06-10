<?php
#
# User Bulk Purge
#
require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->dirroot.'/'.$CFG->admin.'/user/lib.php');
require_once($CFG->dirroot.'/'.$CFG->admin.'/user/user_bulk_forms.php');

//
// Copied from user_bulk_action_form() in admin/user/user_bulk/user_bulk_forms.php
//
class ldapsync_purgeusers_action_form extends moodleform {
    function definition() {
        global $CFG;

        $mform =& $this->_form;

        $syscontext = context_system::instance();
        $actions = array(0=>get_string('choose').'...');
        //if (has_capability('moodle/user:update', $syscontext)) {
        //    $actions[1] = get_string('confirm');
        //}
        //if (has_capability('moodle/site:readallmessages', $syscontext) && !empty($CFG->messaging)) {
        //    $actions[2] = get_string('messageselectadd');
        //}
        if (has_capability('moodle/user:delete', $syscontext)) {
            $actions[3] = get_string('delete');
        }
        $actions[4] = get_string('displayonpage');
        if (has_capability('moodle/user:update', $syscontext)) {
            $actions[5] = get_string('download', 'admin');
        }
        //if (has_capability('moodle/user:update', $syscontext)) {
        //    $actions[7] = get_string('forcepasswordchange');
        //}
        //if (has_capability('moodle/cohort:assign', $syscontext)) {
        //    $actions[8] = get_string('bulkadd', 'core_cohort');
        //}
        $objs = array();
        $objs[] =& $mform->createElement('select', 'action', null, $actions);
        $objs[] =& $mform->createElement('submit', 'doaction', get_string('go'));
        $mform->addElement('group', 'actionsgrp', get_string('withselectedusers'), $objs, ' ', false);
    }
}

admin_externalpage_setup('ldapsync_purgeusers');

if (!isset($SESSION->bulk_users)) {
    $SESSION->bulk_users = array();
}


// @TODO: add timecreated, in_ldap (EDS)

$fieldnames = array('realname' => 1, 'lastname' => 1, 'firstname' => 1, 'username' => 1, 'email' => 1, 'city' => 1,
                    'country' => 1, 'confirmed' => 1, 'suspended' => 1, 'profile' => 1, 'courserole' => 1,
                    'anycourses' => 0, 'systemrole' => 1, 'cohort' => 1, 'firstaccess' => 1, 'lastaccess' => 1,
                    'neveraccessed' => 0, 'timecreated' => 1, 'timemodified' => 1, 'nevermodified' => 1, 'auth' => 0, 'mnethostid' => 1,
                    'idnumber' => 1, 'activeonldap' => 0, 'additionalldapfilter' => 1);

// create the user filter form
$ufiltering = new \tool_ldapsync\user_filtering($fieldnames);

// array of bulk operations
// create the bulk operations form
$action_form = new ldapsync_purgeusers_action_form();
if ($data = $action_form->get_data()) {
    // check if an action should be performed and do so
    switch ($data->action) {
        case 1: redirect($CFG->wwwroot.'/'.$CFG->admin.'/user/user_bulk_confirm.php');
        case 2: redirect($CFG->wwwroot.'/'.$CFG->admin.'/user/user_bulk_message.php');
        // case 3: redirect($CFG->wwwroot.'/'.$CFG->admin.'/user/user_bulk_delete.php');
        case 3: redirect($CFG->wwwroot.'/'.$CFG->admin.'/tool/ldapsync/user_bulk_delete.php');
        // case 4: redirect($CFG->wwwroot.'/'.$CFG->admin.'/user/user_bulk_display.php');
        case 4: redirect($CFG->wwwroot.'/'.$CFG->admin.'/tool/ldapsync/user_bulk_display.php');
        // case 5: redirect($CFG->wwwroot.'/'.$CFG->admin.'/user/user_bulk_download.php');
        case 5: redirect($CFG->wwwroot.'/'.$CFG->admin.'/tool/ldapsync/user_bulk_download.php');
        case 7: redirect($CFG->wwwroot.'/'.$CFG->admin.'/user/user_bulk_forcepasswordchange.php');
        case 8: redirect($CFG->wwwroot.'/'.$CFG->admin.'/user/user_bulk_cohortadd.php');
    }
}

$user_bulk_form = new user_bulk_form(null, get_selection_data($ufiltering));

if ($data = $user_bulk_form->get_data()) {
    if (!empty($data->addall)) {
        add_selection_all($ufiltering);

    } else if (!empty($data->addsel)) {
        if (!empty($data->ausers)) {
            if (in_array(0, $data->ausers)) {
                add_selection_all($ufiltering);
            } else {
                foreach($data->ausers as $userid) {
                    if ($userid == -1) {
                        continue;
                    }
                    if (!isset($SESSION->bulk_users[$userid])) {
                        $SESSION->bulk_users[$userid] = $userid;
                    }
                }
            }
        }

    } else if (!empty($data->removeall)) {
        $SESSION->bulk_users= array();

    } else if (!empty($data->removesel)) {
        if (!empty($data->susers)) {
            if (in_array(0, $data->susers)) {
                $SESSION->bulk_users= array();
            } else {
                foreach($data->susers as $userid) {
                    if ($userid == -1) {
                        continue;
                    }
                    unset($SESSION->bulk_users[$userid]);
                }
            }
        }
    }

    // reset the form selections
    unset($_POST);
    $user_bulk_form = new user_bulk_form(null, get_selection_data($ufiltering));
}
// do output
echo $OUTPUT->header();

$ufiltering->display_add();
$ufiltering->display_active();

$user_bulk_form->display();

$action_form->display();

echo $OUTPUT->footer();
