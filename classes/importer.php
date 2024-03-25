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
 * importer.php
 *
 * @package tool_ldapsync
 * @author Carson Tam <carson.tam@ucsf.edu>
 * @copyright Copyright (c) 2020, UCSF Center for Knowledge Management
 *
 * Run this script on a daily basis via cron job to synchronize user data between a given LDAP server
 * and the moodle database.
 */


namespace tool_ldapsync;
use core_text;
use Exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/ldaplib.php');

/**
 * Class containing methods to import ldap data.
 */
class importer {
    /**
     * @var string MOODLE_TEMP_TABLE name of the temporary DB table in moodle needed for the sync. process
     */
    const MOODLE_TEMP_TABLE = 'ckm_extuser';

    /**
     * @var string MOODLE_AUTH_ADAPTER hardwired to use Shibboleth authentication.
     */
    const MOODLE_AUTH_ADAPTER = 'shibboleth';

    /**
     * @var string LDAP datetime format
     */
    const LDAP_DATETIME_FORMAT = '%Y%m%d%H%M%SZ';

    /**
     * @var int max. limit of DB records that can be processed by a single SQL statement.
     */
    const DB_BATCH_LIMIT = 1000;

     // The fields we can lock and update from/to external authentication backends
     // @var array
    private $userfields = \core_user::AUTHSYNCFIELDS;

    // Moodle custom fields to sync with.
    // @var array()
    private $customfields = null;


    // The configuration details for the plugin.
    // @var object
    protected $config;

    /**
     * @var integer $_ts UNIX timestamp
     */
    protected $_ts = 0;

    /**
     * @var string $_ldapDt LDAP formatted datetime
     */
    protected $_ldapdt = '';

    /**
     * @var array $_ldapmoodleuserattrmap maps user table column names to LDAP user record attribute names
     */
    protected $_ldapmoodleuserattrmap = [
        'uid' => 'uid',
        'edupersonprincipalname' => 'username',
        'givenname' => 'firstname',
        'ucsfedupreferredgivenname' => 'preferred_firstname',
        'sn' => 'lastname',
        'mail' => 'email',
        'ucsfeduidnumber' => 'idnumber',
        'createtimestamp' => 'timecreated',
        'modifytimestamp' => 'timemodified',
        // 'ucsfEduPreferredPronoun' => 'pronoun'
    ];


    /**
     * @param integer $ts
     */
    public function __construct($ts = null) {
        // Making sure php-ldap extension is present
        if (!extension_loaded('ldap')) {
            throw new Exception("php-ldap extension is not loaded in memory. \n");
        }

        // Use $this->config
        $this->config = get_config('tool_ldapsync');

        if (!empty($ts)) {
            $this->_ts = $ts;
        } else if (!empty($this->config->last_synched_on)) {
            $this->_ts = strtotime($this->config->last_synched_on);
        }

        if (!empty($this->_ts)) {
            $this->_ldapDt = self::formatLdapTimestamp($this->_ts);
        }

        if (empty($this->config->ldapencoding)) {
            $this->config->ldapencoding = 'utf-8';
        }
        if (empty($this->config->user_type)) {
            $this->config->user_type = 'default';
        }

        // Merge data mapping info from 'auth_tool_ldapsync'
        $mapconfig = get_config('auth_tool_ldapsync');
        foreach ($mapconfig as $key => $value) {
            $this->config->{$key} = $value;
        }

        $ldapusertypes = ldap_supported_usertypes();
        $this->config->user_type_name = $ldapusertypes[$this->config->user_type];
        unset($ldapusertypes);

        $default = ldap_getdefaults();

        // Use defaults if values not given
        foreach ($default as $key => $value) {
            // watch out - 0, false are correct values too
            if (!isset($this->config->{$key}) || $this->config->{$key} == '') {
                $this->config->{$key} = $value[$this->config->user_type];
            }
        }

        // Hack prefix to objectclass
        $this->config->objectclass = ldap_normalise_objectclass($this->config->objectclass);
    }

    /**
     * Utility function, converts a given UNIX timestamp into a LDAP formatted datetime string.
     * @param integer $ts
     * @return string
     * @todo refactor this out into a utility class
     */
    public static function formatldaptimestamp($ts): string {
        $datetime = \DateTime::createFromFormat('U', $ts);
        return $datetime->format('YmdHis\Z');
    }

    /**
     * 'main' method of the class, runs the synchronization process
     */
    public function run() {
        // 1. get the new/updated entries from LDAP
        $ldap = $this->_connectToLdap();
        $start = time();
        $data = $this->_getUpdatesFromLdap($ldap, $this->_ldapDt);
        $this->ldap_close($ldap); // cleanup
        // 2. add/merge entries into Moodle
        $this->_updateMoodleAccounts($data);
        set_config('last_synched_on', date('c', $start), 'tool_ldapsync');
    }

    /**
     * Checks if user exists on LDAP
     *
     * @param string $username
     */
    public function user_exists($username) {
        $extusername = core_text::convert($username, 'utf-8', $this->config->ldapencoding);

        // Returns true if given username exists on ldap
        $users = $this->ldap_get_userlist('(' . $this->config->user_attribute . '=' . ldap_filter_addslashes($extusername) . ')');
        return count($users);
    }

    /**
     * Check if user exists in ldap
     * @param string $userid
     * return # of users not in LDAP.
     */
    public function check_users_in_ldap($userid) {
        if (is_array($userid)) {
            $userids = array_unique($userid);
            $ldapcampusidproperty = $this->config->user_attribute;
            $filterterms = array_map(function ($campusid) use ($ldapcampusidproperty) {
                return "({$ldapcampusidproperty}={$campusid})";
            }, $userids);
            $users = [];
            // Split into groups of 50 to avoid LDAP query length limits
            foreach (array_chunk($filterterms, 50) as $terms) {
                $filtertermsstring = implode('', $terms);
                $filter = "(|{$filtertermsstring})";
                $users = array_merge($users, $this->ldap_get_userlist($filter));
            }
            return count($userids) - count($users);
        } else {
            return $this->user_exists($userid) >= 1;
        }
    }

    /**
     * Delete never login account
     * @param string $userid
     */
    public function delete_never_login($user) {
        global $DB;

        if (isset($user->lastlogin) && $user->lastlogin == 0) {
            return user_delete_user($user);
        } else {
            $record = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
            if ($record->lastlogin == 0) {
                return user_delete_user($user);
            }
        }
        return false;
    }

    /**
     * Load LDAP data into tool_ldapsync table
     */
    public function load_ldap_data_to_table() {
        global $CFG, $DB;

        $fresult = [];

        $filter = '(&(' . $this->config->user_attribute . '=*)' . $this->config->objectclass . ')';

        $ldapconnection = $this->ldap_connect();

        $contexts = explode(';', $this->config->contexts);
        if (!empty($this->config->create_context)) {
            array_push($contexts, $this->config->create_context);
        }

        $lastupdatedtime = time();
        $ldapusers = [];
        $ldapcookie = '';
        $ldappagedresults = ldap_paged_results_supported($this->config->ldap_version, $ldapconnection);
        $servercontrols = [];
        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty($context)) {
                continue;
            }

            do {
                if ($ldappagedresults) {
                    $servercontrols = $this->get_ldap_controls($this->config, $ldapcookie);
                }
                if ($this->config->search_sub) {
                    // Use ldap_search to find first user from subtree.
                    $ldapresult = ldap_search(
                        $ldapconnection,
                        $context,
                        $filter,
                        ['uid', $this->config->user_attribute, 'createtimestamp', 'modifytimestamp'],
                        controls: $servercontrols
                    );
                } else {
                    // Search only in this context.
                    $ldapresult = ldap_list(
                        $ldapconnection,
                        $context,
                        $filter,
                        ['uid', $this->config->user_attribute, 'createtimestamp', 'modifytimestamp'],
                        controls: $servercontrols
                    );
                }
                if (!$ldapresult) {
                    continue;
                }
                if ($ldappagedresults) {
                    // Get next cookie from controls.
                    ldap_parse_result(
                        $ldapconnection,
                        $ldapresult,
                        $errcode,
                        $matcheddn,
                        $errmsg,
                        $referrals,
                        $controls
                    );
                    if (isset($controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'])) {
                        $ldapcookie = $controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'];
                    }
                }
                $users = ldap_get_entries_moodle($ldapconnection, $ldapresult);
                // Add found users to list.
                for ($i = 0; $i < count($users); $i++) {
                    $uid = core_text::convert($users[$i]['uid'][0], $this->config->ldapencoding, 'utf-8');
                    $cn  = core_text::convert(
                        $users[$i][$this->config->user_attribute][0],
                        $this->config->ldapencoding,
                        'utf-8'
                    );
                    if (!empty($users[$i]['createtimestamp'][0])) {
                        $createtimestamp = strtotime(
                            core_text::convert(
                                $users[$i]['createtimestamp'][0],
                                $this->config->ldapencoding,
                                'utf-8'
                            )
                        );
                    } else {
                        $createtimestamp = 0;
                    }

                    if (!empty($users[$i]['modifytimestamp'][0])) {
                        $modifytimestamp = strtotime(
                            core_text::convert(
                                $users[$i]['modifytimestamp'][0],
                                $this->config->ldapencoding,
                                'utf-8'
                            )
                        );
                    } else {
                        $modifytimestamp = 0;
                    }

                    $select = sprintf("%s = :cn", $DB->sql_compare_text('cn'));
                    if ($rs = $DB->get_record_select('tool_ldapsync', $select, ['cn' => "$cn"])) {
                        $rs->uid = $uid;
                        $rs->cn = $cn;
                        $rs->createtimestamp = $createtimestamp;
                        $rs->modifytimestamp = $modifytimestamp;
                        $rs->lastupdated = $lastupdatedtime;

                        // echo "Updating ldap user {$rs->cn}...";
                        $DB->update_record('tool_ldapsync', $rs);
                        // echo "done.\n";
                    } else {
                        $rs = new stdClass();
                        $rs->uid = $uid;
                        $rs->cn = $cn;
                        $rs->createtimestamp = $createtimestamp;
                        $rs->modifytimestamp = $modifytimestamp;
                        $rs->lastupdated = $lastupdatedtime;

                        echo "Creating ldap user record {$rs->cn}...";
                        $rs->id = $DB->insert_record('tool_ldapsync', $rs, true);
                        echo "done.\n";
                    }
                    $ldapusers[$uid] = $rs;
                }
                unset($ldapresult); // Free mem.
            } while ($ldappagedresults && !empty($ldapcookie));
        }

        // Remove records that are not updated.
        if ($ldappagedresults && empty($ldapcookie)) {
            $select = sprintf("%s < :lastupdatedtime", $DB->sql_compare_text('lastupdated'));
            $cnt = $DB->count_records_select('tool_ldapsync', $select, ['lastupdatedtime' => $lastupdatedtime]);
            echo "Deleting $cnt old records...";
            if (false === $DB->delete_records_select('tool_ldapsync', $select, ['lastupdatedtime' => $lastupdatedtime])) {
                echo "FAILED to delete records: '$select' with 'lastupdatedtime = $lastupdatedtime' \n";
            } else {
                echo "done.\n";
            }
        }

        // If paged results were used, make sure the current connection is completely closed
        $this->ldap_close($ldappagedresults);

        return $ldapusers;
    }

    /**
     * Returns all usernames from LDAP
     * (copy from auth/ldap/auth.php)
     *
     * @param $filter An LDAP search filter to select desired users
     * @return array of LDAP user names converted to UTF-8
     */
    private function _ldap_get_userlist($filter = '*') {
        $userlist = explode(')(edupersonprincipalname=', ltrim(rtrim($filter, ')'), '(|(edupersonprincipalname='));
        for ($i = 0; $i < 5; $i++) {
            // unset($userlist[rand(0, count($userlist))]);
            unset($userlist[$i]);
        }
        return $userlist;
    }

    private function ldap_get_userlist($filter = '*') {
        global $CFG;

        $fresult = [];

        if ($filter == '*') {
            $filter = '(&(' . $this->config->user_attribute . '=*)' . $this->config->objectclass . ')';
            // @TODO: Is there a better way to do this?
            // If cache file exists, use it, to improve performance.
            $cachefile = $CFG->cachedir . '/misc/ldapsync_userlist.json';
            if (file_exists($cachefile)) {
                return json_decode(file_get_contents($cachefile), true);
            }
        }

        $ldapconnection = $this->ldap_connect();

        $contexts = explode(';', $this->config->contexts);
        if (!empty($this->config->create_context)) {
            array_push($contexts, $this->config->create_context);
        }

        $ldapcookie = '';
        $ldappagedresults = ldap_paged_results_supported($this->config->ldap_version, $ldapconnection);
        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty($context)) {
                continue;
            }
            $servercontrols = [];
            do {
                if ($ldappagedresults) {
                    $servercontrols = $this->get_ldap_controls($this->config, $ldapcookie);
                }
                if ($this->config->search_sub) {
                    // Use ldap_search to find first user from subtree.
                    $ldapresult = ldap_search(
                        $ldapconnection,
                        $context,
                        $filter,
                        [$this->config->user_attribute],
                        controls: $servercontrols
                    );
                } else {
                    // Search only in this context.
                    $ldapresult = ldap_list(
                        $ldapconnection,
                        $context,
                        $filter,
                        [$this->config->user_attribute],
                        controls: $servercontrols
                    );
                }
                if (!$ldapresult) {
                    continue;
                }
                if ($ldappagedresults) {
                    // Get next cookie from controls.
                    ldap_parse_result(
                        $ldapconnection,
                        $ldapresult,
                        $errcode,
                        $matcheddn,
                        $errmsg,
                        $referrals,
                        $controls
                    );
                    if (isset($controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'])) {
                        $ldapcookie = $controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'];
                    }
                }

                $users = ldap_get_entries_moodle($ldapconnection, $ldapresult);
                // Add found users to list.
                for ($i = 0; $i < count($users); $i++) {
                    $extuser = core_text::convert(
                        $users[$i][$this->config->user_attribute][0],
                        $this->config->ldapencoding,
                        'utf-8'
                    );
                    array_push($fresult, $extuser);
                }
                unset($ldapresult); // Free mem.
            } while ($ldappagedresults && !empty($ldapcookie));
        }

        // If paged results were used, make sure the current connection is completely closed
        $this->ldap_close($ldappagedresults);
        return $fresult;
    }

    /**
     * Connects and binds to a LDAP server, then returns a handle to it.
     * @return resource the connected and bound LDAP handle
     * @throws Exception if connectivity to LDAP server couldn't be fully established.
     */
    protected function _connecttoldap() {
        echo "Connecting to LDAP server ... ";
        if (!$ldapconnection = $this->ldap_connect()) {
            throw new Exception("Couldn't bind to LDAP server.");
        }
        echo "successfully connected.\n";
        return $ldapconnection;
    }

    /**
     * Connect to the LDAP server, using the plugin configured
     * settings. It's actually a wrapper around ldap_connect_moodle()
     *
     * @return resource A valid LDAP connection (or dies if it can't connect)
     */
    public function ldap_connect() {
        // Cache ldap connections. They are expensive to set up
        // and can drain the TCP/IP ressources on the server if we
        // are syncing a lot of users (as we try to open a new connection
        // to get the user details). This is the least invasive way
        // to reuse existing connections without greater code surgery.
        if (!empty($this->ldapconnection)) {
            $this->ldapconns++;
            return $this->ldapconnection;
        }

        if (
            $ldapconnection = ldap_connect_moodle(
                $this->config->host_url,
                $this->config->ldap_version,
                $this->config->user_type,
                $this->config->bind_dn,
                $this->config->bind_pw,
                $this->config->opt_deref,
                $debuginfo,
                $this->config->start_tls
            )
        ) {
            $this->ldapconns = 1;
            $this->ldapconnection = $ldapconnection;
            return $ldapconnection;
        }

        throw new \moodle_exception('auth_ldap_noconnect_all', 'auth_ldap', '', $debuginfo);
    }

    /**
     * Disconnects from a LDAP server
     *
     * @param force boolean Forces closing the real connection to the LDAP server, ignoring any
     *                      cached connections. This is needed when we've used paged results
     *                      and want to use normal results again.
     */
    public function ldap_close($force = false) {
        $this->ldapconns--;
        if (($this->ldapconns == 0) || ($force)) {
            $this->ldapconns = 0;
            @ldap_close($this->ldapconnection);
            unset($this->ldapconnection);
        }
    }

    /**
     * Searches LDAP for user records that were updated/created after a given datetime.
     * @param resource $ldap the LDAP server handle
     * @param string $baseDn the base DN
     * @param string $ldapTimestamp the datetime
     * @return array nested array of user records
     * @throws Exception if search fails
     */
    protected function _getupdatesfromldap($ldap, $ldaptimestamp = null) {
        if (empty($ldaptimestamp)) {
            echo "Start prowling LDAP for all records... ";
            $filter = '(&(' . $this->config->user_attribute . '=*)' . $this->config->objectclass . ')';
        } else {
            echo "Start prowling LDAP for records added and/or updated since '{$ldaptimestamp}' ... ";
            $filter = '(&(' . $this->config->user_attribute . '=*)' . $this->config->objectclass . "(|(createTimestamp>=$ldaptimestamp)(modifyTimestamp>=$ldaptimestamp))" . ')';
        }

        $attrmap = $this->ldap_attributes();
        $searchattribs = ['uid', 'createtimestamp', 'modifytimestamp'];
        foreach ($attrmap as $key => $values) {
            if (!is_array($values)) {
                $values = [$values];
            }
            foreach ($values as $value) {
                $val = core_text::strtolower(trim($value));
                if (!in_array($val, $searchattribs)) {
                    array_push($searchattribs, $val);
                }
            }
        }

        $ldappagedresults = ldap_paged_results_supported($this->config->ldap_version, $ldap);
        $ldapcookie = '';
        $servercontrols = [];
        $results = [];

        do {
            if ($ldappagedresults) {
                $servercontrols = $this->get_ldap_controls($this->config, $ldapcookie);
            }

            // Search only in this context.
            $ldapresults = ldap_list($ldap, $this->config->contexts, $filter, $searchattribs, controls: $servercontrols);
            if (!$ldapresults) {
                continue;
            }
            if ($ldappagedresults) {
                // Get next cookie from controls.
                ldap_parse_result(
                    $ldap,
                    $ldapresults,
                    $errcode,
                    $matcheddn,
                    $errmsg,
                    $referrals,
                    $controls
                );
                if (isset($controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'])) {
                    $ldapcookie = $controls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'];
                }
            }

            $ldapentry = @ldap_first_entry($ldap, $ldapresults);
            while ($ldapentry) {
                $ldapattrs = ldap_get_attributes($ldap, $ldapentry);
                $ldapattrsls = [];
                $result = [];
                $skip = false;

                // convert key name to lowercase
                foreach ($ldapattrs as $key => $value) {
                    $ldapattrsls[core_text::strtolower($key)] = $value;
                }

                foreach ($searchattribs as $attr) {
                    if ('uid' == $attr) {
                        // always lowercase the UID
                        $result[$attr] = core_text::strtolower($ldapattrsls[$attr][0]);
                    } else {
                        if (isset($ldapattrsls[$attr])) {
                            if ('mail' == $attr) {
                                // Fixing: email field could have multiple email, just extract the first one as default.
                                $email = $ldapattrsls[$attr][0];
                                foreach ([',', ';', ' '] as $delimiter) {
                                    $email = trim(explode($delimiter, $email)[0]);
                                }
                                $result[$attr] = $email;
                            } else if (
                                (core_text::strtolower('sn') == $attr)
                                       || (core_text::strtolower('givenname') == $attr)
                            ) {
                                // Fixing: this field could have 'question mark' in it.
                                // If so, just remove it.  ($DB->execute() does not like '?')
                                if (strstr($ldapattrsls[$attr][0], '?')) {
                                    $result[$attr] = str_replace('?', '', $ldapattrsls[$attr][0]);
                                } else {
                                    $result[$attr] = $ldapattrsls[$attr][0];
                                }
                            } else if (core_text::strtolower('ucsfEduPreferredGivenName') == $attr) {
                                // Fixing: this field could have 'question mark' in it.
                                // If so, do not use.  ($DB->execute() does not like '?')
                                if (strstr($ldapattrsls[$attr][0], '?')) {
                                    $result[$attr] = '';
                                } else {
                                    $result[$attr] = $ldapattrsls[$attr][0];
                                }
                            } else if (('createtimestamp' == $attr) || ('modifytimestamp' == $attr)) {
                                $ts = strtotime(core_text::convert(
                                    $ldapattrsls[$attr][0],
                                    $this->config->ldapencoding,
                                    'utf-8'
                                ));
                                $result[$attr] = empty($ts) ? 0 : $ts;
                            } else {
                                $result[$attr] = $ldapattrsls[$attr][0];
                            }
                        } else {
                            if (core_text::strtolower('eduPersonPrincipalName') == $attr) {   // we will skip this user
                                $skip = true;
                            }
                            $result[$attr] = '';
                        }
                    }
                }
                if (!$skip) {
                    $results[] = $result;
                }

                $ldapentry = ldap_next_entry($ldap, $ldapentry);
            }
            unset($ldapresults); // Free mem.
        } while ($ldappagedresults && $ldapcookie !== null && $ldapcookie != '');

        echo "found " . count($results) . " updated/added records.\n";
        return $results;
    }
    /**
     * Adds/Merges LDAP user accounts into Moodle's database.
     * Most of this has been shamelessly ripped off Moodle's default LDAP auth adapter.
     * @param array $data nested array of updated user records
     * @global $CFG Moodle's global config object
     * @see auth_plugin_ldap::sync_users()
     * @throws Exception on database/SQL related failures
     */
    protected function _updatemoodleaccounts(array $data) {
        global $CFG, $DB;
        if (!count($data)) {
            return;
        }
        // Convert key names to lower case in each record
        // (Should already be lower case.
        // $dataLS = array();
        // foreach ($data as $record) {
        // $recordLS = array();
        // foreach ($record as $key => $value) {
        // $recordLS[core_text::strtolower($key)] = $value;
        // }
        // $dataLS[] = $recordLS;
        // }
        $usertblname = $CFG->prefix . 'user';
        $stagingtblname = $CFG->prefix . self::MOODLE_TEMP_TABLE;
        // attempt to delete a previous temp table
        $deletetemptablesql = "DROP TEMPORARY TABLE IF EXISTS {$stagingtblname}";
        $createtemptablesql = <<< EOL
CREATE TEMPORARY TABLE {$stagingtblname}
(
  uid VARCHAR(100),
  username VARCHAR(100),
  mnethostid BIGINT(10) UNSIGNED,
  firstname VARCHAR(100),
  lastname VARCHAR(100),
  preferred_firstname VARCHAR(100),
  email VARCHAR(100),
  idnumber VARCHAR(255),
  timecreated BIGINT(10) UNSIGNED,
  timemodified BIGINT(10) UNSIGNED,
  -- pronoun VARCHAR(255),
  PRIMARY KEY (username, mnethostid)
)
ENGINE=MyISAM
COLLATE utf8_unicode_ci
EOL;
        echo "Delete temporary table if exists ... ";
        if (!$DB->execute($deletetemptablesql)) {
            echo "Fail to execute SQL, ($deletetemptablesql).";
        } else {
            echo "done.\n";
        }

        echo "Creating staging table ... ";
        if (!$DB->execute($createtemptablesql)) {
            throw new Exception("Couldn't create staging table.");
        }
        echo "done.\n";
        // 1. insert all LDAP records into temp table
        // --------------------------------------------
        $attrnames = array_keys($this->_ldapmoodleuserattrmap);
        $colnames = array_values($this->_ldapmoodleuserattrmap);

        echo "Populating staging table ... ";
        $total = count($data);
        // populate the staging table in batches of DB_INSERT_BATCH_LIMIT records.
        for ($i = 0, $n = (int) ceil($total / self::DB_BATCH_LIMIT); $i < $n; $i++) {
            // build SQL string
            for ($j = $i * self::DB_BATCH_LIMIT, $m = $j + self::DB_BATCH_LIMIT; $j < $m && $j < $total; $j++) {
                $stagingsql =
                    "INSERT IGNORE INTO {$stagingtblname} (mnethostid, " . implode(', ', $colnames) . ')'
                    . ' VALUES (?, ' . implode(', ', array_fill(0, count($colnames), '?')) . ')';
                $stagingsqlvalues = [];
                $record = $data[$j];
                if (!empty($record['edupersonprincipalname'])) {
                    $stagingsqlvalues[] = "{$CFG->mnet_localhost_id}";
                    foreach ($attrnames as $attrname) {
                        $attrvalue = $record[$attrname] ?? '';
                        $stagingsqlvalues[] = $attrvalue;
                    }
                }
                if (!empty($stagingsqlvalues)) {
                    try {
                        $DB->execute($stagingsql, $stagingsqlvalues);
                    } catch (Exception $e) {
                        throw new Exception(
                            "Couldn't populate staging table: "
                            . $e->getMessage() . "\nFailed to insert the following user data:\n"
                            . var_export($stagingsqlvalues, true) . "\n"
                        );
                    }
                    unset($stagingsqlvalues);
                }
                unset($stagingsql);
            }
        }
        echo "done.\n";

        // 2. update existing user records
        // ------------------------------------------
        $sql  = " FROM {$stagingtblname} ";
        $sql .= " JOIN {$usertblname} ON {$stagingtblname}.username = {$usertblname}.username";
        $sql .= " AND {$stagingtblname}.mnethostid = {$usertblname}.mnethostid";
        $sql .= " WHERE {$usertblname}.deleted = 0 AND {$usertblname}.auth = '";
        $sql .= empty($this->config->authtype) ? self::MOODLE_AUTH_ADAPTER : $this->config->authtype;
        $sql .= "'";

        $countsql = "SELECT COUNT(*) as c " . $sql;

        $selectsql = "SELECT {$usertblname}.id, {$stagingtblname}.uid";
        foreach ($colnames as $colname) {
            if (!in_array($colname, ['uid', 'firstname', 'preferred_firstname'])) {
                $selectsql .= ", {$stagingtblname}.{$colname} AS new_{$colname}";
                $selectsql .= ", {$usertblname}.{$colname}";
            }
        }
        // special case "first name": use the preferred first name by default, fall back to first name
        $selectsql .= <<< EOQ
, {$usertblname}.firstname
, CASE
WHEN '' = TRIM(COALESCE({$stagingtblname}.preferred_firstname, '')) THEN {$stagingtblname}.firstname
ELSE {$stagingtblname}.preferred_firstname
END AS new_firstname
EOQ;
        $selectsql .= ' ' . $sql;
        $result = $DB->get_record_sql($countsql);
        $total = (int) $result->c;
        echo "Loading {$total} existing user records for update ... \n";
        $batchnum = (int) ceil($total / self::DB_BATCH_LIMIT);
        echo "(Running updates in {$batchnum} batches of up to " . self::DB_BATCH_LIMIT . " user records each)\n";
        for ($i = 0; $i < $batchnum; $i++) {
            $batchstart = $i * self::DB_BATCH_LIMIT;
            $records = $DB->get_records_sql($selectsql, null, $batchstart, self::DB_BATCH_LIMIT);
            if (!empty($records)) {
                $batchend = (int) min($batchstart + count($records), $total);
                echo '+ Processing records ' . ($batchstart + 1) . " - {$batchend} ... \n";
                foreach ($records as $record) {
                    $user = new stdClass();
                    foreach ($record as $key => $value) {
                        $user->{$key} = $value;
                    }
                    $userid = $user->id;
                    foreach ($colnames as $colname) {
                        $newcolname = 'new_' . $colname;
                        if (
                            ($colname != 'uid') // does not exist in mdl_user table, ignore
                            && ($colname != 'preferred_firstname') // does not exist in mdl_user table, ignore
                            && ($user->{$colname} != $user->{$newcolname})
                        ) {
                            switch ($colname) {
                                // NEVER attempt to update idnumber or username
                                case 'uid':
                                case 'username':
                                case 'idnumber':
                                case 'email':
                                    // if the newly retrieved email address is empty then ignore it.
                                    // @see https://redmine.library.ucsf.edu/issues/36
                                    if (!trim($user->{$newcolname})) {
                                        continue 2;  // continue to update the database
                                    }
                                    break;
                                case 'timecreated':
                                    if (
                                        empty($user->{$colname}) ||
                                        !empty($user->{$newcolname}) &&
                                        ($user->{$colname} > $user->{$newcolname})
                                    ) {
                                        continue 2;  // continue to update the database
                                    }
                                    break;
                                case 'timemodified':
                                    if (
                                        empty($user->{$colname}) &&
                                        !empty($user->{$newcolname}) &&
                                        ($user->{$colname} < $user->{$newcolname})
                                    ) {
                                        continue 2;  // continue to update the database
                                    }
                                    break;
                                default: // do nothing
                            }

                            echo "- Updating user '{$user->username}', attribute '" . $colname . "' to '" . $user->{$newcolname} . "' ... ";
                            if (false === $DB->set_field('user', $colname, $user->{$newcolname}, ['id' => $userid])) {
                                echo "FAIL\n";
                            } else {
                                echo "OK\n";
                                if (($colname === 'timecreated') || ($colname === 'timemodified')) {
                                    $fn = ($colname === 'timecreated') ? 'createtimestamp' : 'modifytimestamp';
                                    $ts = $DB->get_field('tool_ldapsync', $fn, ['cn' => $user->username]);
                                    if ($ts != $user->{$newcolname}) {
                                        echo "- Updating tool_ldapsync table, user '{$user->username}', field '" . $fn . "' to '" . $user->{$newcolname} . "' ... ";
                                        if (false === $DB->set_field('tool_ldapsync', $fn, $user->{$newcolname}, ['cn' => $user->username])) {
                                            echo "FAIL\n";
                                        } else {
                                            echo "OK\n";
                                            $DB->set_field('tool_ldapsync', 'lastupdated', time(), ['cn' => $user->username]);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            unset($records);
        }
        echo "done.\n";
        // 3. create new records
        // ------------------------------------------
        $sql = " FROM {$stagingtblname}";
        $sql .= " LEFT JOIN {$usertblname} ON {$stagingtblname}.username = {$usertblname}.username";
        $sql .= " AND {$stagingtblname}.mnethostid = {$usertblname}.mnethostid WHERE {$usertblname}.id IS NULL";
        // Commented the following line to create accounts with empty email address
        // $sql .= " AND '' <> TRIM(COALESCE({$stagingtblName}.email,''))"; // ignore accounts with empty email addresses

        $selectsql = 'SELECT ';
        foreach ($colnames as $colname) {
            if (!in_array($colname, ['firstname', 'preferred_firstname'])) {
                $selectsql .= " {$stagingtblname}.{$colname}, ";
            }
        }
        // special case "first name": use the preferred first name by default, fall back to first name
        $selectsql .= <<< EOQ
CASE
WHEN '' = TRIM(COALESCE({$stagingtblname}.preferred_firstname, '')) THEN {$stagingtblname}.firstname
ELSE {$stagingtblname}.preferred_firstname
END AS firstname
EOQ;
        $selectsql .= ' ' . $sql;
        $countsql = "SELECT COUNT(*) AS c " . $sql;

        $result = $DB->get_record_sql($countsql);
        $total = (int) $result->c;

        echo "Loading {$total} new user records for insertion ...\n";
        $batchnum = (int) ceil($total / self::DB_BATCH_LIMIT);
        echo "(Running insertions in {$batchnum} batches of up to " . self::DB_BATCH_LIMIT . " user records each)\n";
        for ($i = 0; $i < $batchnum; $i++) {
            $batchstart = $i * self::DB_BATCH_LIMIT;
            $records = $DB->get_records_sql($selectsql, null, 0, self::DB_BATCH_LIMIT);
            if (!empty($records)) {
                $batchend = (int) min($batchstart + count($records), $total);
                echo '+ Processing records ' . ($batchstart + 1) . " - {$batchend} ... \n";
                foreach ($records as $record) {
                    // convert user array to object
                    $user = new stdClass();
                    foreach ($record as $key => $value) {
                        $user->{$key} = $value;
                    }
                    $user->confirmed = 1;
                    $user->auth = empty($this->config->authtype) ? self::MOODLE_AUTH_ADAPTER : $this->config->authtype;
                    $user->mnethostid = $CFG->mnet_localhost_id;
                    $user->trackforums = 1;     // #3834: We want to set trackforums to be the default.
                    if (empty($user->lang)) {
                        $user->lang = $CFG->lang;
                    }
                    try {
                        $id = $DB->insert_record('user', $user);
                    } catch (Exception $e) {
                        echo "- Failed to create user '{$user->username}' with the following error: {$e->getMessage()}.\n";
                        continue;
                    }
                    $user->id = $id;
                    if (!empty($this->config->forcechangepassword)) {
                        set_user_preference('auth_forcepasswordchange', 1, $user->id);
                    }
                    echo "- Created user '{$user->username}'.\n";

                    // Update tool_ldapsync table
                    $select = sprintf("%s = :cn", $DB->sql_compare_text('cn'));
                    if ($rs = $DB->get_record_select('tool_ldapsync', $select, ['cn' => $user->username])) {
                        $rs->createtimestamp = $user->timecreated;
                        $rs->modifytimestamp = $user->timemodified;
                        $rs->lastupdated = time();

                        echo "- Updating tool_ldapsync table, user '{$user->username}', attributes\n" . var_export($rs, 1);
                        $DB->update_record('tool_ldapsync', $rs);
                    } else {
                        $rs = new stdClass();
                        $rs->uid = $user->uid;
                        $rs->cn = $user->username;
                        $rs->createtimestamp = $user->timecreated;
                        $rs->modifytimestamp = $user->timemodified;
                        $rs->lastupdated = time();

                        echo "- Inserting tool_ldapsync table, user '{$user->username}', attributes\n" . var_export($rs, 1);
                        $rs->id = $DB->insert_record('tool_ldapsync', $rs, true);
                    }
                }
            }
            unset($records);
        }
        echo "done.\n";
    }

    /**
     * Return custom user profile fields.
     *
     * @return array list of custom fields.
     */
    public function get_custom_user_profile_fields() {
        global $DB;
        // If already retrieved then return.
        if (!is_null($this->customfields)) {
            return $this->customfields;
        }

        $this->customfields = [];
        if ($proffields = $DB->get_records('user_info_field')) {
            foreach ($proffields as $proffield) {
                $this->customfields[] = 'profile_field_' . $proffield->shortname;
            }
        }
        unset($proffields);

        return $this->customfields;
    }

    /**
     * Returns user attribute mappings between moodle and LDAP
     *
     * @return array
     */

    public function ldap_attributes() {
        $moodleattributes = [];
        // If we have custom fields then merge them with user fields.
        $customfields = $this->get_custom_user_profile_fields();
        if (!empty($customfields) && !empty($this->userfields)) {
            $userfields = array_merge($this->userfields, $customfields);
        } else {
            $userfields = $this->userfields;
        }

        foreach ($userfields as $field) {
            if (!empty($this->config->{"field_map_$field"})) {
                $moodleattributes[$field] = core_text::strtolower(trim($this->config->{"field_map_$field"}));
                if (preg_match('/,/', $moodleattributes[$field])) {
                    $moodleattributes[$field] = explode(',', $moodleattributes[$field]); // split ?
                }
            }
        }
        $moodleattributes['username'] = core_text::strtolower(trim($this->config->user_attribute));
        $moodleattributes['suspended'] = core_text::strtolower(trim($this->config->suspended_attribute));
        return $moodleattributes;
    }

    /**
     * Test a DN
     *
     * @param resource $ldapconn
     * @param string $dn The DN to check for existence
     * @param string $message The identifier of a string as in get_string()
     * @param string|object|array $a An object, string or number that can be used
     *      within translation strings as in get_string()
     * @return true or a message in case of error
     */
    private function test_dn($ldapconn, $dn, $message, $a = null) {
        $ldapresult = @ldap_read($ldapconn, $dn, '(objectClass=*)', []);
        if (!$ldapresult) {
            if (ldap_errno($ldapconn) == 32) {
                // No such object.
                return get_string($message, 'auth_ldap', $a);
            }

            $a = ['code' => ldap_errno($ldapconn), 'subject' => $a, 'message' => ldap_error($ldapconn)];
            return get_string('diag_genericerror', 'auth_ldap', $a);
        }

        return true;
    }

    /**
     * Test if settings are correct, print info to output.
     */
    public function test_settings() {
        global $OUTPUT;

        if (!function_exists('ldap_connect')) { // Is php-ldap really there?
            echo $OUTPUT->notification(get_string('auth_ldap_noextension', 'auth_ldap'), \core\output\notification::NOTIFY_ERROR);
            return;
        }

        // Check to see if this is actually configured.
        if (empty($this->config->host_url)) {
            // LDAP is not even configured.
            echo $OUTPUT->notification(get_string('ldapnotconfigured', 'auth_ldap'), \core\output\notification::NOTIFY_ERROR);
            return;
        }

        if ($this->config->ldap_version != 3) {
            echo $OUTPUT->notification(get_string('diag_toooldversion', 'auth_ldap'), \core\output\notification::NOTIFY_WARNING);
        }

        try {
            $ldapconn = $this->ldap_connect();
        } catch (Exception $e) {
            echo $OUTPUT->notification($e->getMessage(), \core\output\notification::NOTIFY_ERROR);
            return;
        }

        // Display paged file results.
        if (!ldap_paged_results_supported($this->config->ldap_version, $ldapconn)) {
            echo $OUTPUT->notification(get_string('pagedresultsnotsupp', 'auth_ldap'), \core\output\notification::NOTIFY_INFO);
        }

        // Check contexts.
        foreach (explode(';', $this->config->contexts) as $context) {
            $context = trim($context);
            if (empty($context)) {
                echo $OUTPUT->notification(get_string('diag_emptycontext', 'auth_ldap'), \core\output\notification::NOTIFY_WARNING);
                continue;
            }

            $message = $this->test_dn($ldapconn, $context, 'diag_contextnotfound', $context);
            if ($message !== true) {
                echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_WARNING);
            }
        }

        $this->ldap_close(true);
        // We were able to connect successfuly.
        echo $OUTPUT->notification(get_string('connectingldapsuccess', 'auth_ldap'), \core\output\notification::NOTIFY_SUCCESS);
    }

    /**
     * Creates LDAP Controls that allow for result pagination.
     * @param object $config the plugin configuration
     * @param string $ldapcookie the LDAP cookie
     * @return array the Controls
     * @link https://www.php.net/manual/en/ldap.examples-controls.php
     * @link https://www.php.net/manual/en/ldap.controls.php
     */
    protected function get_ldap_controls(object $config, string $ldapcookie): array {
        return [
            [
                'oid' => LDAP_CONTROL_PAGEDRESULTS,
                'value' => [
                    'size' => $config->pagesize,
                    'cookie' => $ldapcookie,
                ],
            ],
        ];
    }
}
