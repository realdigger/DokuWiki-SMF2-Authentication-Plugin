<?php
/**
 * Syntax Plugin for authsmf20.
 *
 * @package SMF DocuWiki
 * @file syntax.php
 * @author digger <digger@mysmf.net>
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @version 1.0
 */

if (!defined('DOKU_INC')) {
    die();
}

/*
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
*/

var_dump('111'); die;

/**
 * Syntax class for authsmf20 plugin.
 */
class syntax_plugin_authsmf20 extends DokuWiki_Syntax_Plugin
{

    function getType()
    {
        return 'substition';
    }

    function getSort()
    {
        return 315;
    }

    function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern("{{(?:gr|)avatar>.+?}}", $mode, 'plugin_avatar');
    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {
        list($syntax, $match) = explode('>', substr($match, 0, -2), 2); // strip markup
        list($user, $title) = explode('|', $match, 2); // split title from mail / username

        // Check alignment
        $ralign = (bool)preg_match('/^ /', $user);
        $lalign = (bool)preg_match('/ $/', $user);
        if ($lalign & $ralign) {
            $align = 'center';
        } else {
            if ($ralign) {
                $align = 'right';
            } else {
                if ($lalign) {
                    $align = 'left';
                } else {
                    $align = null;
                }
            }
        }

        //split into src and size parameter (using the very last questionmark)
        list($user, $param) = explode('?', trim($user), 2);
        if (preg_match('/^s/', $param)) {
            $size = 20;
        } else {
            if (preg_match('/^m/', $param)) {
                $size = 40;
            } else {
                if (preg_match('/^l/', $param)) {
                    $size = 80;
                } else {
                    if (preg_match('/^xl/', $param)) {
                        $size = 120;
                    } else {
                        $size = null;
                    }
                }
            }
        }

        return array($user, $title, $align, $size);
    }

    function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode == 'xhtml') {
            if ($my =& plugin_load('helper', 'avatar')) {
                $renderer->doc .= '<span class="vcard">' .
                    $my->getXHTML($data[0], $data[1], $data[2], $data[3]) .
                    '</span>';
            }
            return true;
        }
        return false;
    }
}
// vim:ts=4:sw=4:et:enc=utf-8:
