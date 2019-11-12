<?php
/**
 * Aliases for special pages of the Html2Wiki extension.  This file defines
 * how the special page will be addressed in other languages
 *
 * When your special page code uses either
 * SpecialPage::getTitleFor( 'MyExtension' ) or $this->getTitle()
 * (in the class that provides Special:MyExtension),
 * the localized alias will be used, if it's available.
 *
 * @file
 * @ingroup Extensions
 */

$specialPageAliases = [];

/** English (English)
 * @author freephile
 */
$specialPageAliases['en'] = [
	'Html2Wiki' => [ 'Html2Wiki', 'Html2Wiki' ],
];
