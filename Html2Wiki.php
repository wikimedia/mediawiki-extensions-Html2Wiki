<?php
/**
 * Html2Wiki extension - enables you to import HTML content into your wiki.
 *
 * @link https://mediawiki.org/wiki/Extension:Html2Wiki For more info
 * (when available)
 *
 * @file
 * @ingroup Extensions
 * @author Greg Rundlett @link http://eQuality-Tech.com eQuality Technology
 * @license GPL-2.0-or-later
 *
 * This file is part of the Html2Wiki Extension to MediaWiki
 * @link https://www.mediawiki.org/wiki/Extension:Html2Wiki
 *
 *
 * @section LICENSE
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'Html2Wiki' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['Html2Wiki'] = __DIR__ . '/i18n';
	$wgExtensionMessagesFiles['Html2WikiAlias'] = __DIR__ . '/Html2Wiki.i18n.alias.php';
	wfWarn(
		'Deprecated PHP entry point used for the Html2Wiki extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the Html2Wiki extension requires MediaWiki 1.32+' );
}
