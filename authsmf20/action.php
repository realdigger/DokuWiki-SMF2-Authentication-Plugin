<?php
/**
 * Action Plugin for authsmf20.
 *
 * @package SMF DokuWiki
 * @file action.php
 * @author digger <digger@mysmf.net>
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @version 1.0 beta1
 */

if (!defined('DOKU_INC')) {
    die();
}

/**
 * Action class for authsmf20 plugin.
 */
class action_plugin_authsmf20 extends DokuWiki_Plugin
{

    /**
     * Registers a callback function for a given event.
     *
     * @param Doku_Event_Handler $controller
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('COMMON_USER_LINK', 'AFTER', $this, 'hookUserLink');
    }


    /**
     * Adds a link to SMF member profile for user's name.
     *
     * @param Doku_Event $event
     */
    public function hookUserLink(&$event)
    {
        global $auth, $conf;
        $userlink = '<a href="%s" class="interwiki iw_user" rel="nofollow" target="_blank">%s</a>';

        if (empty($event->data['name'])) {
            $event->data['name'] = $event->data['username'];
        }

        if ($conf['showuseras'] !== 'username_link' && $conf['showuseras'] !== 'username') {
            return;
            // TODO: Add other variants: loginname, email, email_link
        }

        $data = $auth->getUserData($event->data['username']);

        if (!empty($data['smf_user_id'])) {
            $event->data['userlink'] = sprintf($userlink, $data['smf_user_profile'], $data['smf_user_realname']);
            $event->data['name'] = $data['smf_user_realname'];
        } else {
            $event->data['userlink'] = sprintf($userlink, '#', $event->data['name']);
        }

        if ($conf['showuseras'] == 'username') {
            $event->data['userlink'] = $event->data['name'];
        }

        $event->data['userlink'] = $this->renderProfileLink($data);
    }

    /*
	 * Render all availiable information as a XHTML link to user's profile
	 * @param  $userinfo   array of all nessesary data for creating a link
	 * @param  $popup  display popup at top of a link ('top'), bottom ('bottom') or don't display at all ('none')
	 * @return string  XHTML markup for link to user's profile
	*/
    public function renderProfileLink($userinfo, $popup = 'bottom')
    {
        if ($userinfo['smf_user_profile']) {
            // Build basic link
            $result = '<a href="' . $userinfo['smf_user_profile'] . '" class="userlink' .
                ($userinfo['smf_user_gender'] ? ' gender-' . hsc($userinfo['smf_user_gender']) : ' gender-unknown') . '">' .
                hsc($userinfo['smf_user_realname']);

            // Test if we have some data to show
            //$fields = array_map('trim', explode(",", $this->getConf('fields')));
            $fields = array('smf_personal_text', 'smf_user_usertitle');

            $is_fields = false;
            foreach ($fields as $field) {
                if ($userinfo[$field]) {
                    $is_fields = true;
                }
            }

            // If we should display popup and have some data for it...
            if (($popup == 'top' or $popup == 'bottom') and
                ($userinfo['smf_user_avatar'] or $is_fields)) {
                $result .= '<span class="userlink-popup ' . $popup . '">';
                if ($userinfo['smf_user_avatar']) {
                    $result .= '<img src="' . $userinfo['smf_user_avatar'] . '" alt="' . hsc($userinfo['smf_user_realname']) . '" />';
                }
                $result .= '<span><strong>' . hsc($userinfo['smf_user_realname']) . '</strong>';

                foreach ($fields as $field) {
                    if ($userinfo[$field]) {
                        $result .= '<span class="userlink-' . hsc($field) . '">' . hsc($userinfo[$field]) . '</span>';
                    }
                }
                $result .= '</span></span></a>';

            } else {
                $result .= '</a>';
            }
            return $result;
        } else {
            return hsc($userinfo['smf_user_realname']);
        }
    }

}
