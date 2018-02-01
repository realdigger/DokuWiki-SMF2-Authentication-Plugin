<?php
/**
 * Authentication Plugin for authsmf20.
 *
 * @package SMF DokuWiki
 * @file auth.php
 * @author digger <digger@mysmf.net>
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @version 1.0 beta1
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
        $_smf_user_is_banned = false,
        $_smf_user_avatar = '',
        $_smf_user_groups = array(),
        $_smf_user_profile = '',
        $_cache = null,
        $_cache_duration = 0,
        $_cache_ext_name = '.authsmf20';

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
     * @return  boolean True on successful auth
     */
    public function trustExternal($user = '', $pass = '', $sticky = false)
    {
        global $USERINFO;

        $sticky ? $sticky = true : $sticky = false;

        if ($this->doLoginCookie()) {
            return true; // User already logged in
        }

        if ($user) {
            $is_logged = $this->checkPass($user, $pass); // Try to login over DokuWiki login form
        } else {
            $is_logged = $this->doLoginSSI(); // Try to login over SMF SSI API
        }

        if (!$is_logged) {
            if ($user) {
                msg($this->getLang('login_error'), -1);
            }
            return false;
        }

        $USERINFO['name'] = $_SESSION[DOKU_COOKIE]['auth']['user'] = $this->_smf_user_username;
        $USERINFO['mail'] = $_SESSION[DOKU_COOKIE]['auth']['mail'] = $this->_smf_user_email;
        $USERINFO['grps'] = $_SESSION[DOKU_COOKIE]['auth']['grps'] = $this->_smf_user_groups;
        $_SESSION[DOKU_COOKIE]['auth']['info'] = $USERINFO;
        $_SERVER['REMOTE_USER'] = $USERINFO['name'];

        return true;
    }

    /**
     * Log off the current user
     */
    public function logOff()
    {
        $link = ssi_logout(DOKU_URL, 'array');
        preg_match('/href="(.+)"/iU', $link, $url);
        unset($_SESSION[DOKU_COOKIE]);

        //send_redirect($url[1]);
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

            $this->_smf_conf['path'] = rtrim(trim($this->getConf('smf_path')), '\/');

            if (!file_exists($this->_smf_conf['path'] . '/SSI.php')) {
                dbglog('SMF not found in path' . $this->_smf_conf['path']);
                return false;
            }

            $ssi_guest_access = true;
            include_once($this->_smf_conf['path'] . '/SSI.php');

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
    private function doLoginSSI()
    {
        $user_info = ssi_welcome('array');

        if (empty($user_info['is_logged'])) {
            return false;
        }

        $this->_smf_user_id = $user_info['id'];
        $this->_smf_user_username = $user_info['username'];
        $this->_smf_user_email = $user_info['email'];
        $this->getUserGroups();

        return true;
    }

    /**
     * Authenticate the user using DokuWiki Cookie.
     *
     * @return  boolean True on successful login
     */
    private function doLoginCookie()
    {
        global $USERINFO;

        if (empty($_SESSION[DOKU_COOKIE]['auth']['info'])) {
            return false;
        }

        $USERINFO['name'] = $_SESSION[DOKU_COOKIE]['auth']['user'];
        $USERINFO['mail'] = $_SESSION[DOKU_COOKIE]['auth']['mail'];
        $USERINFO['grps'] = $_SESSION[DOKU_COOKIE]['auth']['grps'];

        $_SERVER['REMOTE_USER'] = $_SESSION[DOKU_COOKIE]['auth']['user'];

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

        if (!$this->connectSmfDB() || !$this->_smf_user_id) {
            return false;
        }

        $query = "SELECT mg.group_name, m.id_group
                  FROM {$this->_smf_conf['db_prefix']}members m
                  LEFT JOIN {$this->_smf_conf['db_prefix']}membergroups mg ON mg.id_group = m.id_group OR FIND_IN_SET (mg.id_group, m.additional_groups) OR mg.id_group = m.id_post_group
                  WHERE m.id_member = {$this->_smf_user_id}";

        $result = $this->_smf_db_link->query($query);

        if (!$result) {
            dbglog("cannot get groups for user id: {$this->_smf_user_id}");
            return false;
        }

        while ($row = $result->fetch_object()) {
            if ($row->id_group == 1) {
                $this->_smf_user_groups[] = 'admin'; // Map SMF Admin to DokuWiki Admin
            } else {
                $this->_smf_user_groups[] = $row->group_name;
            }
        }

        if (!$this->_smf_user_is_banned) {
            $this->_smf_user_groups[] = 'user';
        } // Banned users as guests
        $this->_smf_user_groups = array_unique($this->_smf_user_groups);
var_dump($this->_smf_user_groups);
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
     * array['realname']        string  User's real name
     * array['username']        string  User's username
     * array['email']           string  User's email address
     * array['smf_user_id']     string  User's ID
     * array['smf_profile']     string  User's link to profile
     * array['smf_user_groups'] array   User's groups
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

            $query = "SELECT m.id_member, m.real_name, m.email_address, m.gender, m.location, m.usertitle, m.personal_text, m.signature, IF(m.avatar = '', a.id_attach, m.avatar) AS avatar
                      FROM {$this->_smf_conf['db_prefix']}members m
                      LEFT JOIN {$this->_smf_conf['db_prefix']}attachments a ON a.id_member = m.id_member AND a.id_msg = 0
                      WHERE member_name = '{$user}'";

            $result = $this->_smf_db_link->query($query);

            if (!$result) {
                dbglog("No data found in database for user: {$user}");
                return false;
            }

            $row = $result->fetch_object();

            $this->_smf_user_id = $row->id_member;
            $this->getUserGroups();

            $user_data['smf_user_groups'] = array_unique($this->_smf_user_groups);
            $user_data['smf_user_id'] = $row->id_member;
            $user_data['smf_user_username'] = $user;
            $user_data['smf_user_realname'] = $row->real_name;

            if (empty($user_data['smf_user_realname'])) {
                $user_data['smf_user_realname'] = $user_data['smf_user_username'];
            }

            $user_data['smf_user_email'] = $row->email_address;

            if ($row->gender == 1) {
                $user_data['smf_user_gender'] = 'male';
            } elseif ($row->gender == 2) {
                $user_data['smf_user_gender'] = 'female';
            } else {
                $user_data['smf_user_gender'] = 'unknown';
            }

            $user_data['smf_user_location'] = $row->location;
            $user_data['smf_user_usertitle'] = $row->usertitle;
            $user_data['smf_personal_text'] = $row->personal_text;
            $user_data['smf_user_profile'] = $this->_smf_conf['boardurl'] . '/index.php?action=profile;u=' . $this->_smf_user_id;
            $user_data['smf_user_avatar'] = $this->getAvatarUrl($row->avatar);

            $result->close();
            unset($row);

            $cache->storeCache(serialize($user_data));
        }

        $cache = null;
        unset($cache);
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
     * @return  bool   True for success, false otherwise
     */

    public function checkPass($user = '', $pass = '')
    {
        $check = ssi_checkPassword($user, $pass, true);

        if (empty($check)) {
            return false;
        }

        $user_data = ssi_queryMembers('member_name = {string:user}', array('user' => $user), 1, 'id_member', 'array');
        $user_data = array_shift($user_data);

        $this->_smf_user_id = $user_data['id'];
        $this->_smf_user_username = $user_data['username'];
        $this->_smf_user_email = $user_data['email'];
        $this->getUserGroups();

        return true;
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

    /**
     * Get avatar url
     *
     * @param string $avatar
     * @return string avatar url
     */
    private function getAvatarUrl($avatar = '')
    {
        $avatar = trim($avatar);

        // No avatar
        if (empty($avatar)) {
            return '';
        } elseif ($avatar == (string)(int)$avatar) {
            // Avatar uploaded as attachment
            return $this->_smf_conf['boardurl'] . '/index.php?action=dlattach;attach=' . $avatar . ';type=avatar';
        } elseif (preg_match('#^https?://#i', $avatar)) {
            // Avatar is a link to external image
            return $avatar;
        } else {
            // Avatar from SMF library
            return $this->_smf_conf['boardurl'] . '/avatars/' . $avatar;
        }
        // TODO: Custom avatars url
        // TODO: Default avatar for empty one
    }
}
