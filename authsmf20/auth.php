<?php
/**
 * Authentication Plugin for authsmf20.
 *
 * @package SMF DocuWiki
 * @file auth.php
 * @author digger <digger@mysmf.net>
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @version 1.0
 */

/*
 * Sign in DokuWiki via SMF database. This file is a part of authsmf20 plugin
 * and must be in plugin directory for correct work.
 *
 * Requirements: SMF 2.x with utf8 encoding in database and subdomain independent cookies
 * SMF - Admin - Server Settings - Cookies and Sessions: Use subdomain independent cookies
 * Tested with DokuWiki 2017-02-19b "Frusterick Manners"
*/

if (!defined('DOKU_INC')) {
    die();
}

/**
 * SMF 2.0 Authentication class.
 */
class auth_plugin_authsmf20 extends DokuWiki_Auth_Plugin
{
    protected $_smf_db_link = null;

    protected $_smf_conf = array(
        'path' => '',
        'boardurl' => '',
        'db_server' => '',
        'db_port' => 3306,
        'db_name' => '',
        'db_user' => '',
        'db_passwd' => '',
        'db_character_set' => '',
        'db_prefix' => '',
    );

    protected
        $_smf_user_id = 0,
        $_smf_user_realname = '',
        $_smf_user_username = '',
        $_smf_user_email = '',
        $_smf_user_is_admin = false,
        $_smf_user_is_banned = false,
        $_smf_user_avatar = '',
        $_smf_user_groups = array(),
        $_smf_user_profile = '',
        $_cache = null,
        $_cache_duration = 0,
        $_cache_ext_name = '.smf20cache';

    CONST CACHE_DURATION_UNIT = 86400;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->cando['addUser'] = false;
        $this->cando['delUser'] = false;
        $this->cando['modLogin'] = false;
        $this->cando['modPass'] = false;
        $this->cando['modName'] = false;
        $this->cando['modMail'] = false;
        $this->cando['modGroups'] = false;
        $this->cando['getUsers'] = false;
        $this->cando['getUserCount'] = false;
        $this->cando['getGroups'] = true;
        $this->cando['external'] = true;
        $this->cando['logout'] = true;

        $this->success = $this->loadConfiguration();

        if (!$this->success) {
            msg($this->getLang('config_error'), -1);
        }
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->disconnectSmfDB();
        $this->_cache = null;

    }

    /**
     * Do all authentication
     *
     * @param   string $user Username
     * @param   string $pass Cleartext Password
     * @param   boolean $sticky Cookie should not expire
     * @return  boolean         True on successful auth
     */
    public function trustExternal($user, $pass, $sticky = false)
    {
        global $USERINFO;

        if (empty($user) || empty($pass)) {
            $is_logged = $this->loginSSI();
        } else {
            $is_logged = $this->checkPass($user, $pass);
        }

        if (!$is_logged || empty($this->_smf_user_username) || empty($this->_smf_user_email)) {
            return false;
        }

        $USERINFO['name'] = $this->_smf_user_username;
        $USERINFO['mail'] = $this->_smf_user_email;
        $USERINFO['grps'] = $this->_smf_user_groups;
        $_SERVER['REMOTE_USER'] = $USERINFO['name'];
        $_SESSION[DOKU_COOKIE]['auth']['user'] = $USERINFO['name'];
        $_SESSION[DOKU_COOKIE]['auth']['info'] = $USERINFO;

        return true;
    }

    /**
     * Log off the current user
     */
    public function logOff()
    {
        if ($this->_smf_user_id && !empty($this->_smf_conf['boardurl'])) {
            global $context;

            include_once($this->_smf_conf['path'] . '/SSI.php');
            $url = $this->_smf_conf['boardurl'] . '/index.php?action=logout;' . $context['session_var'] . '=' . $context['session_id'];
            // $_SESSION['logout_url'] = DOKU_URL;
            send_redirect($url);
        }
    }

    /**
     * Loads the plugin configuration.
     *
     * @return  boolean True on success, false otherwise
     */
    private function loadConfiguration()
    {
        if ($this->getSmfCache()) {
            $this->_smf_conf = unserialize($this->_cache->retrieveCache(false));
        } else {
            $this->_cache->removeCache();

            $this->_smf_conf['path'] = trim($this->getConf('smf_path'));

            if (!@file_exists($this->_smf_conf['path'] . '/SSI.php')) {
                dbglog('SMF not found in path' . $this->_smf_conf['path']);
                return false;
            }

            include($this->_smf_conf['path'] . '/SSI.php');

            $this->_smf_conf['boardurl'] = $boardurl;
            $this->_smf_conf['db_server'] = $db_server;
            $this->_smf_conf['db_name'] = $db_name;
            $this->_smf_conf['db_user'] = $db_user;
            $this->_smf_conf['db_passwd'] = $db_passwd;
            $this->_smf_conf['db_character_set'] = $db_character_set;
            $this->_smf_conf['db_prefix'] = $db_prefix;


            $this->_cache->storeCache(serialize($this->_smf_conf));
        }

        return (!empty($this->_smf_conf['boardurl']));
    }

    /**
     * Authenticate the user using SMF SSI.
     *
     * @return  boolean True on successful login
     */
    private function loginSSI()
    {
        if (!$this->connectSmfDB()) {
            return false;
        }

        include_once($this->_smf_conf['path'] . '/SSI.php');
        $user_info = ssi_welcome('array');

        if (empty($user_info['is_logged'])) {
            return false;
        }

        $this->_smf_user_id = $user_info['id'];
        $this->_smf_user_username = $user_info['username'];
        $this->_smf_user_email = $user_info['email'];
        $this->_smf_user_is_admin = $user_info['is_admin'];
        $this->_smf_user_is_banned = $user_info['is_banned'];
        $this->_smf_user_avatar = $user_info['avatar'];
        $this->getUserGroups();

        return true;
    }

    /**
     * Connect to SMF database.
     *
     * @return  boolean True on success, false otherwise
     */
    private function connectSmfDB()
    {
        if (!$this->_smf_db_link) {
            $this->_smf_db_link = new mysqli(
                $this->_smf_conf['db_server'], $this->_smf_conf['db_user'],
                $this->_smf_conf['db_passwd'], $this->_smf_conf['db_name'],
                (int)$this->_smf_conf['db_port']
            );

            if (!$this->_smf_db_link || $this->_smf_db_link->connect_error) {
                $error = 'Cannot connect to database server';

                if ($this->_smf_db_link) {
                    $error .= ' (' . $this->_smf_db_link->connect_errno . ')';
                }
                dbglog($error);
                msg($this->getLang('database_error'), -1);
                $this->_smf_db_link = null;

                return false;
            }

            if ($this->_smf_conf['db_character_set'] == 'utf8') {
                $this->_smf_db_link->set_charset('utf8');
            }
        }
        return ($this->_smf_db_link && $this->_smf_db_link->ping());
    }

    /**
     * Disconnects from SMF database.
     */
    private function disconnectSmfDB()
    {
        if ($this->_smf_db_link !== null) {
            $this->_smf_db_link->close();
            $this->_smf_db_link = null;
        }
    }

    /**
     * Use cache for SMF configuration.
     *
     * @return  Object for Cache SMF configuration settings
     */
    private function getSmfCache()
    {
        $depends = array();

        if ($this->_cache === null) {

            $this->_cache = new cache('authsmf20', $this->_cache_ext_name);
        }

        $this->_cache_duration = intval($this->getConf('smf_cache'));
        if ($this->_cache_duration > 0) {
            $depends['age'] = self::CACHE_DURATION_UNIT * $this->_cache_duration;
        } else {
            $depends['purge'] = true;
        }
        return $this->_cache->useCache($depends);
    }


    /**
     * Get SMF user's groups.
     *
     * @return  boolean True for success, false otherwise
     */
    private function getUserGroups()
    {
        if (!$this->connectSmfDB()) {
            return false;
        }

        $this->_smf_user_groups = array();
        $this->_smf_user_id = (int)$this->_smf_user_id;

        if (!$this->_smf_user_id) {
            return false;
        }

        $query = "SELECT m.id_group, m.additional_groups, mg.group_name
                  FROM {$this->_smf_conf['db_prefix']}members m
                  JOIN {$this->_smf_conf['db_prefix']}membergroups mg ON mg.id_group = m.id_group OR FIND_IN_SET (mg.id_group, m.additional_groups) OR mg.id_group = m.id_post_group
                  WHERE m.id_member = {$this->_smf_user_id}";

        $result = $this->_smf_db_link->query($query);

        if (!$result) {
            dbglog("cannot get groups for user id: {$this->_smf_user_id}");
            return false;
        }

        while ($row = $result->fetch_object()) {
            $this->_smf_user_groups[] = $row->group_name;
        }

        // Map SMF Admin to DocuWiki Admin
        if ($this->_smf_user_is_admin) {
            $this->_smf_user_groups[] = 'admin';
        }

        $result->close();
        unset($row);
        return true;
    }

    /**
     * Return user info
     *
     * Returns info about the given user needs to contain
     * at least these fields:
     *
     * name string  full name of the user
     * mail string  email address of the user
     * grps array   list of groups the user is in
     *
     * @param   string $user User name
     * @param   bool $requireGroups Whether or not the returned data must include groups
     * @return  false|array Containing user data or false
     *
     * array['realname']    string  User's real name
     * array['username']    string  User's username
     * array['email']       string  User's email address
     * array['smf_user_id'] string  User's ID
     * array['smf_profile'] string  User's link to profile
     * array['grps']        array   User's groups
     */
    public function getUserData($user, $requireGroups = true)
    {
        if (empty($user)) {
            return false;
        }

        $user_data = false;

        $this->_cache_duration = (int)($this->getConf('smf_cache'));
        $depends = array('age' => self::CACHE_DURATION_UNIT * $this->_cache_duration);
        $cache = new cache('authsmf20_getUserData_' . $user, $this->_cache_ext_name);

        if (($this->_cache_duration > 0) && $cache->useCache($depends)) {
            $user_data = unserialize($cache->retrieveCache(false));
        } else {

            $cache->removeCache();

            if (!$this->connectSmfDB()) {
                return false;
            }

            $user = $this->_smf_db_link->real_escape_string($user);

            $query = "SELECT id_member, real_name, email_address, avatar
                      FROM {$this->_smf_conf['db_prefix']}members
                      WHERE member_name = '{$user}'";

            $result = $this->_smf_db_link->query($query);

            if (!$result) {
                dbglog("No data found in database for user: {$user}");
                return false;
            }

            $row = $result->fetch_object();

            $this->_smf_user_id = $row->id_member;
            $this->getUserGroups();

            $user_data['smf_user_id'] = $row->id_member;
            $user_data['smf_user_username'] = $user;
            $user_data['smf_user_realname'] = $row->real_name;
            $user_data['smf_user_email'] = $row->email_address;
            $user_data['smf_user_avatar'] = $row->avatar;
            $user_data['smf_user_profile'] = $this->_smf_conf['boardurl'] . '/index.php?action=profile;u=' . $this->_smf_user_id;
            $user_data['grps'] = $this->_smf_user_groups;

            if (empty($user_data['smf_user_realname'])) {
                $user_data['smf_user_realname'] = $user_data['smf_user_username'];
            }

            $result->close();
            unset($row);

            $cache->storeCache(serialize($user_data));
        }

        $cache = null;
        return $user_data;
    }


    /**
     * Retrieve groups
     *
     * @param   int $start
     * @param   int $limit
     * @return  array|false Containing groups list, false if error
     */
    public function retrieveGroups($start = 0, $limit = 10)
    {
        if (!$this->connectSmfDB()) {
            return false;
        }

        $query = "SELECT group_name
                  FROM {$this->_smf_conf['db_prefix']}membergroups
                  LIMIT {$start}, {$limit}";

        $result = $this->_smf_db_link->query($query);

        if (!$result) {
            dbglog("Cannot get SMF groups list");
            return false;
        }

        while ($row = $result->fetch_object()) {
            $groups[] = $row->group_name;
        }

        $result->close();
        unset($row);

        return $groups;
    }

    /**
     * Checks if the given user exists and the given
     * plaintext password is correct
     *
     * @param   string $user User name
     * @param   string $pass Clear text password
     * @return  bool            True for success, false otherwise
     */
    public function checkPass($user = '', $pass = '')
    {
        $check = false;

        if (!$this->connectSmfDB() || !$user || !$pass) {
            return false;
        }

        $user = $this->_smf_db_link->real_escape_string($user);

        $query = "SELECT id_member, passwd, email_address
                      FROM {$this->_smf_conf['db_prefix']}members
                      WHERE member_name = '{$user}'";

        $result = $this->_smf_db_link->query($query);

        if (!$result) {
            dbglog("User {$user} not found in SMF database");
            return false;
        }

        $row = $result->fetch_object();

        if ($row->passwd == sha1(strtolower($user) . $pass)) {

            $this->_smf_user_id = $row->id_member;
            $this->_smf_user_username = $user;
            $this->_smf_user_email = $row->email_address;
            $this->getUserGroups();
            $check = true;
        }

        $result->close();
        unset($row);

        return $check;
    }

    /**
     * Sanitize a given username
     *
     * @param string $user username
     * @return string the cleaned username
     */
    public function cleanUser($user)
    {
        return trim($user);
    }
}
