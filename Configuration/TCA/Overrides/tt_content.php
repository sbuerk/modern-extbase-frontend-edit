<?php

declare(strict_types=1);

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

// Adds the two plugins to the content element type selection, which is what
// makes "modernextbasefrontendedit_list" and "modernextbasefrontendedit_show"
// valid CType values of a tt_content record.
//
// No plugin type is passed here and none can be: on TYPO3 v13
// `ExtensionUtility::registerPlugin()` reads it back from what
// `configurePlugin()` stored in $GLOBALS (`ExtensionUtility.php:147`), falling
// back to "list_type" when nothing was registered, and on v14 the parameter no
// longer exists at all. The order that makes the v13 lookup succeed is the
// natural one — ext_localconf.php is loaded before the TCA overrides — so the
// registration in ext_localconf.php must stay there and must keep naming
// PLUGIN_TYPE_CONTENT_ELEMENT.
//
// `ExtensionManagementUtility::addPlugin()` seeds
// $GLOBALS['TCA']['tt_content']['types'][<CType>] from the "header" type on
// both versions, so the plugins are editable in the backend without a showitem
// definition of their own. A concrete extension built from this template adds
// one when it needs more than the header fields.
ExtensionUtility::registerPlugin(
    'ModernExtbaseFrontendEdit',
    'List',
    'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:plugin.list.title',
    'content-plugin',
    'plugins',
    'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:plugin.list.description',
);

ExtensionUtility::registerPlugin(
    'ModernExtbaseFrontendEdit',
    'Show',
    'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:plugin.show.title',
    'content-plugin',
    'plugins',
    'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf:plugin.show.description',
);
