<?php

/**
* importer.php
*
* @package tool
* @subpackage ldapsync
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

require_once($CFG->libdir.'/ldaplib.php');

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

    /**
     * The fields we can lock and update from/to external authentication backends
     * @var array
     */
    var $userfields = \core_user::AUTHSYNCFIELDS;

    /**
     * Moodle custom fields to sync with.
     * @var array()
     */
    var $customfields = null;

    /**
     * The configuration details for the plugin.
     * @var object
     */
    // protected $config;
    var $config;

	/**
	 * @var integer $_ts UNIX timestamp
	 */
	protected $_ts = 0;

	/**
	 * @var string $_ldapDt LDAP formatted datetime
	 */
	protected $_ldapDt = '';

	/**
	 * @var array $_ldapMoodleUserAttrMap maps user table column names to LDAP user record attribute names
	 */
	protected $_ldapMoodleUserAttrMap = array (
        'edupersonprincipalname' => 'username',
        'givenname' => 'firstname',
        'ucsfedupreferredgivenname' => 'preferred_firstname',
        'sn' => 'lastname',
        'mail' => 'email',
        'ucsfeduidnumber' => 'idnumber'
        // 'createTimestamp' => 'timecreated',
        // 'modifyTimestamp' => 'timemodified',
        // 'ucsfEduPreferredPronoun' => 'pronoun'
	);


	/**
	* @param integer $ts
	*/
	public function __construct ($ts = null)
	{
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

        $ldap_usertypes = ldap_supported_usertypes();
        $this->config->user_type_name = $ldap_usertypes[$this->config->user_type];
        unset($ldap_usertypes);

        $default = ldap_getdefaults();

        // Use defaults if values not given
        foreach ($default as $key => $value) {
            // watch out - 0, false are correct values too
            if (!isset($this->config->{$key}) or $this->config->{$key} == '') {
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
	public static function formatLdapTimestamp($ts) {
		return strftime(self::LDAP_DATETIME_FORMAT, $ts);
	}

	/**
	 * 'main' method of the class, runs the synchronization process
	 */
	public function run ()
	{
		// 1. get the new/updated entries from LDAP
		$ldap = $this->_connectToLdap();
        $start = time();
		$data = $this->_getUpdatesFromLdap($ldap, $this->_ldapDt);
		$this->ldap_close($ldap); //cleanup
		// 2. add/merge entries into Moodle
		$this->_updateMoodleAccounts($data);
        set_config('last_synched_on', date('c', $start), 'tool_ldapsync');
	}

    /**
     * Checks if user exists on LDAP
     *
     * @param string $username
     */
    function user_exists($username) {
        $extusername = core_text::convert($username, 'utf-8', $this->config->ldapencoding);

        // Returns true if given username exists on ldap
        $users = $this->ldap_get_userlist('('.$this->config->user_attribute.'='.ldap_filter_addslashes($extusername).')');
        return count($users);
    }

	/**
	 * Check if user exists in ldap
     * @param string $userid
     * return # of users not in LDAP.
	 */
    public function check_users_in_ldap( $userid )
    {
        if (is_array($userid)) {
            $userids = array_unique( $userid );
            $ldapCampusIdProperty = $this->config->user_attribute;
            $filterTerms = array_map(function ($campusId) use ($ldapCampusIdProperty) {
                return "({$ldapCampusIdProperty}={$campusId})";
            }, $userids);
            $users = [];
            //Split into groups of 50 to avoid LDAP query length limits
            foreach (array_chunk($filterTerms, 50) as $terms) {
                $filterTermsString = implode($terms, '');
                $filter = "(|{$filterTermsString})";
                $users = array_merge($users, $this->ldap_get_userlist($filter));
            }
            return count($userids) - count($users);
        } else {
            return $this->user_exists( $userid ) - 1;
        }
    }

    /**
     * Delete never login account
     * @param string $userid
     */
    public function delete_never_login( $user ) {
        global $DB;

        if (isset ($user->lastlogin) && $user->lastlogin == 0) {
            return user_delete_user( $user );
        } else {
            $record = $DB->get_record('user', array('id' => $user->id), '*', MUST_EXIST);
            if ($record->lastlogin == 0) {
                return user_delete_user( $user );
            }
        }
        return false;
    }

    /**
     * Returns all usernames from LDAP
     * (copy from auth/ldap/auth.php)
     *
     * @param $filter An LDAP search filter to select desired users
     * @return array of LDAP user names converted to UTF-8
     */
    function _ldap_get_userlist($filter='*') {
        $userlist = explode(')(edupersonprincipalname=', ltrim(rtrim($filter,')'),'(|(edupersonprincipalname='));
        for ($i = 0; $i < 5; $i++) {
            // unset($userlist[rand(0, count($userlist))]);
            unset($userlist[$i]);
        }
        return $userlist;
    }

    function ldap_get_userlist($filter='*') {
        global $CFG;

        $fresult = array();

        if ($filter == '*') {
           $filter = '(&('.$this->config->user_attribute.'=*)'.$this->config->objectclass.')';
           // @TODO: Is there a better way to do this?
           // If cache file exists, use it, to improve performance.
           $cachefile = $CFG->cachedir.'/misc/ldapsync_userlist.json';
           if (file_exists($cachefile)) {
               return json_decode(file_get_contents($cachefile), true);
           }
        }

        $ldapconnection = $this->ldap_connect();

        $contexts = explode(';', $this->config->contexts);
        if (!empty($this->config->create_context)) {
            array_push($contexts, $this->config->create_context);
        }

        $ldap_cookie = '';
        $ldap_pagedresults = ldap_paged_results_supported($this->config->ldap_version, $ldapconnection);
        foreach ($contexts as $context) {
            $context = trim($context);
            if (empty($context)) {
                continue;
            }

            do {
                if ($ldap_pagedresults) {
                    ldap_control_paged_result($ldapconnection, $this->config->pagesize, true, $ldap_cookie);
                }
                if ($this->config->search_sub) {
                    // Use ldap_search to find first user from subtree.
                    $ldap_result = ldap_search($ldapconnection, $context, $filter, array($this->config->user_attribute));
                } else {
                    // Search only in this context.
                    $ldap_result = ldap_list($ldapconnection, $context, $filter, array($this->config->user_attribute));
                }
                if(!$ldap_result) {
                    continue;
                }
                if ($ldap_pagedresults) {
                    ldap_control_paged_result_response($ldapconnection, $ldap_result, $ldap_cookie);
                }
                $users = ldap_get_entries_moodle($ldapconnection, $ldap_result);
                // Add found users to list.
                for ($i = 0; $i < count($users); $i++) {
                    $extuser = core_text::convert($users[$i][$this->config->user_attribute][0],
                                                $this->config->ldapencoding, 'utf-8');
                    array_push($fresult, $extuser);
                }
                unset($ldap_result); // Free mem.
            } while ($ldap_pagedresults && !empty($ldap_cookie));
        }

        // If paged results were used, make sure the current connection is completely closed
        $this->ldap_close($ldap_pagedresults);
        return $fresult;
    }

	/**
	 * Connects and binds to a LDAP server, then returns a handle to it.
	 * @return resource the connected and bound LDAP handle
	 * @throws Exception if connectivity to LDAP server couldn't be fully established.
	 */
	protected function _connectToLdap ()
	{
        echo "Connecting to LDAP server ... ";
        if(!$ldapconnection = $this->ldap_connect()) {
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
    function ldap_connect() {
        // Cache ldap connections. They are expensive to set up
        // and can drain the TCP/IP ressources on the server if we
        // are syncing a lot of users (as we try to open a new connection
        // to get the user details). This is the least invasive way
        // to reuse existing connections without greater code surgery.
        if(!empty($this->ldapconnection)) {
            $this->ldapconns++;
            return $this->ldapconnection;
        }

        if($ldapconnection = ldap_connect_moodle($this->config->host_url, $this->config->ldap_version,
                                                 $this->config->user_type, $this->config->bind_dn,
                                                 $this->config->bind_pw, $this->config->opt_deref,
                                                 $debuginfo, $this->config->start_tls)) {
            $this->ldapconns = 1;
            $this->ldapconnection = $ldapconnection;
            return $ldapconnection;
        }

        print_error('auth_ldap_noconnect_all', 'auth_ldap', '', $debuginfo);
    }

    /**
     * Disconnects from a LDAP server
     *
     * @param force boolean Forces closing the real connection to the LDAP server, ignoring any
     *                      cached connections. This is needed when we've used paged results
     *                      and want to use normal results again.
     */
    function ldap_close($force=false) {
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
	protected function _getUpdatesFromLdap ($ldap, $ldapTimestamp = null)
	{
        if (empty($ldapTimestamp)) {
            echo "Start prowling LDAP for all records... ";
            $filter = '(&('.$this->config->user_attribute.'=*)'.$this->config->objectclass.')';
        } else {
            echo "Start prowling LDAP for records added and/or updated since '{$ldapTimestamp}' ... ";
            $filter = '(&('.$this->config->user_attribute.'=*)'.$this->config->objectclass."(|(createTimestamp>=$ldapTimestamp)(modifyTimestamp>=$ldapTimestamp))".')';
        }

        $attrmap = $this->ldap_attributes();
        // $search_attribs = array('timecreated' => 'createTimestamp', 'timemodified' => 'modifyTimestamp');
        $search_attribs = array('createTimestamp', 'modifyTimestamp');
        foreach ($attrmap as $key => $values) {
            if (!is_array($values)) {
                $values = array($values);
            }
            foreach ($values as $value) {
                $val = trim($value);
                if (!in_array($val, $search_attribs)) {
                    array_push($search_attribs, $val);
                }
            }
        }

        $ldappagedresults = ldap_paged_results_supported($this->config->ldap_version, $ldap);
        $ldapcookie = '';
        $results = array();

        do {
            if ($ldappagedresults) {
                ldap_control_paged_result($ldap, $this->config->pagesize, true, $ldapcookie);
            }

            $ldapResults = ldap_list($ldap, $this->config->contexts, $filter, $search_attribs); // we only wanna go down 1 lvl into the directory tree
            if (!$ldapResults) {
                continue;
            }
            if ($ldappagedresults) {
                $pagedresp = ldap_control_paged_result_response($ldap, $ldapResults, $ldapcookie);
                // Function ldap_control_paged_result_response() does not overwrite $ldapcookie if it fails, by
                // setting this to null we avoid an infinite loop.
                if ($pagedresp === false) {
                    $ldapcookie = null;
                }
            }


            $ldapEntry = @ldap_first_entry($ldap, $ldapResults);
            while ($ldapEntry) {
                $ldapAttrs = ldap_get_attributes($ldap, $ldapEntry);
                $ldapAttrsLS = array();
                $result = array();
                $skip = false;

                // convert key name to lowercase
                foreach ($ldapAttrs as $key => $value) {
                    $ldapAttrsLS[core_text::strtolower($key)] = $value;
                }

                foreach ($search_attribs as $attr) {
                    if ('uid' == $attr) {
                        // always lowercase the UID
                        $result[$attr] = core_text::strtolower($ldapAttrsLS[$attr][0]);
                    } else {
                        if (isset($ldapAttrsLS[$attr])) {
                            if ('mail' == $attr) {
                                // Fixing: email field could have multiple email, just extract the first one as default.
                                $email = $ldapAttrsLS[$attr][0];
                                foreach ( array(',',';',' ') as $delimiter ) {
                                    $email = trim(explode($delimiter, $email)[0]);
                                }
                                $result[$attr] = $email;
                            } else if (core_text::strtolower('ucsfEduPreferredGivenName') == $attr) {
                                // Fixing: this field could have 'question mark' in it.
                                //         If so, do not use.  ($DB->execute() does not like '?')
                                if (strstr($ldapAttrsLS[$attr][0], '?')) {
                                    $result[$attr] = '';
                                } else {
                                    $result[$attr] = $ldapAttrsLS[$attr][0];
                                }
                            } else {
                                $result[$attr] = $ldapAttrsLS[$attr][0];
                            }
                        } else {
                            if (core_text::strtolower('eduPersonPrincipalName') == $attr)   // we will skip this user
                                $skip = true;
                            $result[$attr] = '';
                        }
                    }
                }
                if (!$skip)
                    $results[] = $result;

                $ldapEntry = ldap_next_entry($ldap, $ldapEntry);
            }
            unset($ldapResults); // Free mem.
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
	protected function _updateMoodleAccounts (array $data)
	{
		global $CFG, $DB;
		if (!count($data)) {
			return;
		}
        // Convert key names to lower case in each record
        $dataLS = array();
        foreach ($data as $record) {
            $recordLS = array();
            foreach ($record as $key => $value) {
                $recordLS[core_text::strtolower($key)] = $value;
            }
            $dataLS[] = $recordLS;
        }
		$userTblName = $CFG->prefix . 'user';
		$stagingtblName = $CFG->prefix . self::MOODLE_TEMP_TABLE;
		// attempt to delete a previous temp table
		$deleteTempTableSql = "DROP TEMPORARY TABLE IF EXISTS {$stagingtblName}";
        $createTempTableSql =<<< EOL
CREATE TEMPORARY TABLE {$stagingtblName}
(
  username VARCHAR(100),
  mnethostid BIGINT(10) UNSIGNED,
  firstname VARCHAR(100),
  lastname VARCHAR(100),
  preferred_firstname VARCHAR(100),
  email VARCHAR(100),
  idnumber VARCHAR(255),
  -- timecreated BIGINT(10) UNSIGNED,
  -- timemodified BIGINT(10) UNSIGNED,
  -- pronoun VARCHAR(255),
  PRIMARY KEY (username, mnethostid)
)
ENGINE=MyISAM
COLLATE utf8_unicode_ci
EOL;
        echo "Delete temporary table if exists ... ";
        if (!$DB->execute($deleteTempTableSql)) {
            echo "Fail to execute SQL, ($deleteTempTableSql).";
        } else {
            echo "done.\n";
        }

		echo "Creating staging table ... ";
        if (!$DB->execute($createTempTableSql)) {
        	throw new Exception("Couldn't create staging table.");
        }
        echo "done.\n";
		//1. insert all LDAP records into temp table
		//--------------------------------------------
		$attrNames = array_keys($this->_ldapMoodleUserAttrMap);
		$colNames = array_values($this->_ldapMoodleUserAttrMap);


        echo "Populating staging table ... ";
        $total = count($dataLS);
        // populate the staging table in batches of DB_INSERT_BATCH_LIMIT records.
        for($i = 0, $n = (int) ceil($total / self::DB_BATCH_LIMIT); $i < $n; $i++) {
            // build SQL string
            for ($j = $i * self::DB_BATCH_LIMIT, $m = $j + self::DB_BATCH_LIMIT; $j < $m && $j < $total; $j++) {
                $stagingSql = "INSERT IGNORE INTO {$stagingtblName} (mnethostid, " . implode(', ', $colNames) . ') VALUES';
                $stagingSqlValues = "";
                $record = $dataLS[$j];
                if (!empty($record['edupersonprincipalname'])) {
                    $stagingSqlValues .= "\n( '{$CFG->mnet_localhost_id}'";
                    $record = $this->_addslashes_recursive($record); // escape output
                    foreach ($attrNames as $attrName) {
                        $attrValue = $record[$attrName] ?? '';
                        $stagingSqlValues .= ", '" . $attrValue . "'";
                    }
                    $stagingSqlValues .= "),";
                }
                if (!empty($stagingSqlValues)) {
                    $stagingSql = $stagingSql . rtrim($stagingSqlValues, ','); // trim trailing comma
                    // insert data into SQL table
                    try {
                        $DB->execute($stagingSql);
                    } catch (Exception $e) {
                        throw new Exception ("Couldn't populate staging table: ". $e->getMessage(). "\nUnable to execute this SQL:\n  $stagingSql \n");
                    }
                    unset($stagingSqlValues);
                }
                unset($stagingSql);
            }
        }
        echo "done.\n";

        // 2. update existing user records
        //------------------------------------------
       	$sql  = " FROM {$stagingtblName} ";
        $sql .= " JOIN {$userTblName} ON {$stagingtblName}.username = {$userTblName}.username";
        $sql .= " AND {$stagingtblName}.mnethostid = {$userTblName}.mnethostid";
        $sql .= " WHERE {$userTblName}.deleted = 0 AND {$userTblName}.auth = '";
        $sql .= empty($this->config->authtype) ? self::MOODLE_AUTH_ADAPTER : $this->config->authtype;
        $sql .= "'";

        $countSql = "SELECT COUNT(*) as c " . $sql;

	    $selectSql = "SELECT {$userTblName}.id";
       	foreach ($colNames as $colName) {
       	    if (!in_array($colName, array('firstname', 'preferred_firstname'))) {
       		    $selectSql .= ", {$stagingtblName}.{$colName} AS new_{$colName}";
       		    $selectSql .= ", {$userTblName}.{$colName}";
       	    }
       	}
       	// special case "first name": use the preferred first name by default, fall back to first name
       	$selectSql .= <<< EOQ
, {$userTblName}.firstname
, CASE
WHEN '' = TRIM(COALESCE({$stagingtblName}.preferred_firstname, '')) THEN {$stagingtblName}.firstname
ELSE {$stagingtblName}.preferred_firstname
END AS new_firstname
EOQ;
       	$selectSql .= ' ' . $sql;
        $result = $DB->get_record_sql($countSql);
        $total = (int) $result->c;
        echo "Loading {$total} existing user records for update ... \n";
        $batchNum = (int) ceil($total / self::DB_BATCH_LIMIT);
        echo "(Running updates in {$batchNum} batches of up to " . self::DB_BATCH_LIMIT . " user records each)\n";
        for ($i = 0; $i < $batchNum; $i++) {
            $batchStart = $i * self::DB_BATCH_LIMIT;
            $records = $DB->get_records_sql($selectSql, null, $batchStart, self::DB_BATCH_LIMIT);
            if (!empty($records)) {
                $batchEnd = (int) min($batchStart + count($records), $total);
        	    echo '+ Processing records ' . ($batchStart + 1) . " - {$batchEnd} ... \n";
                foreach ($records as $record) {
            		$user = new stdClass();
            		foreach ($record as $key => $value) {
    		            $user->{$key} = $value;
            		}
            		$userid = $user->id;
            		foreach ($colNames as $colName) {
            	    	$newColName = 'new_' . $colName;
            		    if (($colName != 'preferred_firstname') // does not exist in mdl_user table, ignore
                            && ($user->{$colName} != $user->{$newColName})) {
            		        switch ($colName) {
            		            // NEVER attempt to update idnumber or username
            		            case 'username' :
            		            case 'idnumber' :
            		            case 'email' :
            		                // if the newly retrieved email address is empty then ignore it.
            		                // @see https://redmine.library.ucsf.edu/issues/36
                                    if (!trim($user->{$newColName})) {
                                        continue 2;
                                    }
            		                break;
            		            default : // do nothing
            		        }

                			echo "- Updating user '{$user->username}', attribute '" . $colName . "' ... ";
                			if (false === $DB->set_field('user', $colName, $user->{$newColName}, array('id' => $userid))) {
                				echo "FAIL\n";
                			} else {
            	    			echo "OK\n";
            		    	}
            		    }
            	    }
                }
            }
            unset($records);
        }
        echo "done.\n";
        // 3. create new records
        //------------------------------------------
        $sql = " FROM {$stagingtblName}";
        $sql .= " LEFT JOIN {$userTblName} ON {$stagingtblName}.username = {$userTblName}.username";
        $sql .= " AND {$stagingtblName}.mnethostid = {$userTblName}.mnethostid WHERE {$userTblName}.id IS NULL";
        // Commented the following line to create accounts with empty email address
        //$sql .= " AND '' <> TRIM(COALESCE({$stagingtblName}.email,''))"; // ignore accounts with empty email addresses

        $selectSql = 'SELECT ';
       	foreach ($colNames as $colName) {
       	    if (!in_array($colName, array('firstname', 'preferred_firstname'))) {
       		    $selectSql .= " {$stagingtblName}.{$colName}, ";
       	    }
       	}
       	// special case "first name": use the preferred first name by default, fall back to first name
       	$selectSql .= <<< EOQ
CASE
WHEN '' = TRIM(COALESCE({$stagingtblName}.preferred_firstname, '')) THEN {$stagingtblName}.firstname
ELSE {$stagingtblName}.preferred_firstname
END AS firstname
EOQ;
        $selectSql .= ' ' . $sql;
        $countSql = "SELECT COUNT(*) AS c " . $sql;

        $result = $DB->get_record_sql($countSql);
        $total = (int) $result->c;

        echo "Loading {$total} new user records for insertion ...\n";
        $batchNum = (int) ceil($total / self::DB_BATCH_LIMIT);
        echo "(Running insertions in {$batchNum} batches of up to " . self::DB_BATCH_LIMIT . " user records each)\n";
        for ($i = 0; $i < $batchNum; $i++) {
            $batchStart = $i * self::DB_BATCH_LIMIT;
            $records = $DB->get_records_sql($selectSql, null, 0, self::DB_BATCH_LIMIT);
            if (!empty($records)) {
        	    $batchEnd = (int) min($batchStart + count($records), $total);
        	    echo '+ Processing records ' . ($batchStart + 1) . " - {$batchEnd} ... \n";
                foreach ($records as $record) {
            	    // convert user array to object
            	    $user = new stdClass();
        		    foreach ($record as $key => $value) {
                        $user->{$key} = $value;
            		}
                    $user->modified = time();
                    $user->confirmed = 1;
                    $user->auth = empty($this->config->authtype) ? self::MOODLE_AUTH_ADAPTER : $this->config->authtype;
                    $user->mnethostid = $CFG->mnet_localhost_id;
                    $user->trackforums = 1;     // #3834: We want to set trackforums to be the default.
                    if (empty($user->lang)) {
                        $user->lang = $CFG->lang;
                    }
 				    $id = $DB->insert_record('user', $user);
                    if ($id) {
                        $user->id = $id;
                        if (!empty($this->config->forcechangepassword)) {
                            set_user_preference('auth_forcepasswordchange', 1, $user->id);
                        }
                        echo "- Created user '{$user->username}'.\n";
                    } else {
                        echo "- Failed to create user '{$user->username}'.\n";
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

        $this->customfields = array();
        if ($proffields = $DB->get_records('user_info_field')) {
            foreach ($proffields as $proffield) {
                $this->customfields[] = 'profile_field_'.$proffield->shortname;
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

    public function ldap_attributes () {
        $moodleattributes = array();
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
        $ldapresult = @ldap_read($ldapconn, $dn, '(objectClass=*)', array());
        if (!$ldapresult) {
            if (ldap_errno($ldapconn) == 32) {
                // No such object.
                return get_string($message, 'auth_ldap', $a);
            }

            $a = array('code' => ldap_errno($ldapconn), 'subject' => $a, 'message' => ldap_error($ldapconn));
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
     * Recursive implementation of addslashes()
     *
     * This function will allow you to add the slashes from a variable.
     * If the variable is an array or object, slashes will be added
     * to the items (or properties) it contains, even if they are arrays
     * or objects themselves.
     *
     * @param mixed the variable to add slashes from
     * @return mixed
     */
    protected function _addslashes_recursive($var) {
        if (is_object($var)) {
            $new_var = new stdClass();
            $properties = get_object_vars($var);
            foreach($properties as $property => $value) {
                $new_var->$property = $this->_addslashes_recursive($value);
            }

        } else if (is_array($var)) {
            $new_var = array();
            foreach($var as $property => $value) {
                $new_var[$property] = $this->_addslashes_recursive($value);
            }

        } else if (is_string($var)) {
            $new_var = addslashes($var);

        } else { // nulls, integers, etc.
            $new_var = $var;
        }

        return $new_var;
    }
}
