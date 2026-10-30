<?php
/**
 * AVIF migration - ACP module language file.
 *
 * @copyright (c) 2026 Slamb
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'ACP_AVIFMIGRATE'      => 'AVIF migrace',
	'ACP_AVIFMIGRATE_MAIN' => 'Převod stávajících obrázků',
));
