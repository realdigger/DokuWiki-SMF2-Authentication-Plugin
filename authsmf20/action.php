<?php
/**
 * Action Plugin for authsmf20.
 *
 * @package SMF DocuWiki
 * @file action.php
 * @author digger <digger@mysmf.net>
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @version 1.0
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
        $controller->register_hook('COMMON_USER_LINK', 'AFTER', $this, 'hook_user_link');
    }


    /**
     * Adds a link to SMF member profile for user's name.
     *
     * @param Doku_Event $event
     */
    public function hook_user_link(&$event)
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
    }
}