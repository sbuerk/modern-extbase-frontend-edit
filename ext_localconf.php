<?php

declare(strict_types=1);

use SBUERK\ModernExtbaseFrontendEdit\Controller\ProfileController;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

// Registers the controller actions of the two read plugins and the TypoScript
// rendering definition of their content elements.
//
// The plugin type is passed explicitly, and it has to be:
//
// - On TYPO3 v13.4 the fifth parameter still defaults to "list_type"
//   (`ExtensionUtility::PLUGIN_TYPE_PLUGIN`, `ExtensionUtility.php:55`) and
//   triggers an E_USER_DEPRECATED for it (`:57`) — which this repository's test
//   suites treat as a failure.
// - On TYPO3 v14 the plugin content element is gone: the constant
//   `PLUGIN_TYPE_PLUGIN` no longer exists (referencing it is an "Undefined
//   constant" Error), the parameter defaults to "CType"
//   (`ExtensionUtility.php:28,52`), and anything else throws
//   \InvalidArgumentException 1730801526 (`:53-55`).
//
// Naming PLUGIN_TYPE_CONTENT_ELEMENT is the one call that is correct on both,
// so this file needs no core version switch.
//
// This is also where the dependency on EXT:fluid_styled_content is created,
// which is why it is declared in composer.json and in ext_emconf.php. The
// TypoScript this call generates references "lib.contentElement"
// (`ExtensionUtility.php:65`), a library object that Fluid Styled Content
// provides and core itself does not (Breaking #80412). The dependency is real
// whether or not it is declared — undeclared, both plugins simply render
// nothing, which is a far worse failure mode than an unsatisfiable requirement
// at install time. Overriding the generated definition with a content object of
// our own was the alternative and was rejected: it would reimplement the header
// and appearance handling every other content element gets for free, and a
// template repository should start from the conventional setup rather than from
// an exception to it.
//
// Both actions are registered as **non-cacheable**. Their output depends on the
// logged-in frontend user (the display-only "editable" flag), while the TYPO3
// page cache identifier varies by frontend user *group* ids rather than by user
// uid — two users in one group would share a cache entry. Extbase reads the
// list back in `Bootstrap::isExtbaseRequestCacheable()` and renders the plugin
// as USER_INT, which removes the question instead of defending against it.
ExtensionUtility::configurePlugin(
    'ModernExtbaseFrontendEdit',
    'List',
    [
        ProfileController::class => 'list',
    ],
    [
        ProfileController::class => 'list',
    ],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT,
);

ExtensionUtility::configurePlugin(
    'ModernExtbaseFrontendEdit',
    'Show',
    [
        ProfileController::class => 'show',
    ],
    [
        ProfileController::class => 'show',
    ],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT,
);

// Classic TypoScript, next to the site set in "Configuration/Sets/". Site sets
// exist since TYPO3 v13.1 (Feature #103437) and are therefore available on both
// target versions, but they are opt-in per site: an installation that has not
// adopted them still configures its sites through sys_template records, and
// this extension supports both.
//
// The content is inlined rather than read from a file, because ext_localconf.php
// is compiled into a cached PHP file — a file_get_contents() here would run on
// every request. The TYPO3 core does the same in its own ext_localconf.php
// files.
//
// Both calls leave the second argument at its default `true`, so the content is
// also added to the "siteSets" scope. That scope is included *before* the sets
// of a site (`SysTemplateTreeBuilder::createSiteTemplateInclude()`), which is
// exactly the wanted order: these values are the defaults, and the site set
// below overrides them from the site settings.
ExtensionManagementUtility::addTypoScriptConstants('
plugin.tx_modernextbasefrontendedit {
    persistence {
        # Comma separated list of page uids the profile records are stored on.
        # There is no "all pages" value: Extbase turns an empty configuration
        # into the page id list [0] (`QueryFactory::create()`), and profiles do
        # not live on the root level, so an unconfigured plugin lists nothing.
        storagePid = 0
    }
    settings {
        # The page holding the "show" plugin. The list links its entries here.
        showPageUid = 0
        # The page holding the edit plugin. The list links here for profiles the
        # current frontend user owns. The edit plugin is a later change; the
        # list does not assume it exists and renders no link while this is 0.
        editPageUid = 0
    }
}
');

ExtensionManagementUtility::addTypoScriptSetup('
plugin.tx_modernextbasefrontendedit {
    mvc {
        # An unresolvable or missing "profile" argument of the show plugin
        # becomes the configured 404 response of the site instead of an
        # exception page. Handled by
        # `ActionController::handleArgumentMappingExceptions()`, Feature
        # #104321, available since TYPO3 v13.3.
        showPageNotFoundIfTargetNotFoundException = 1
        showPageNotFoundIfRequiredArgumentIsMissingException = 1
    }
    persistence {
        storagePid = {$plugin.tx_modernextbasefrontendedit.persistence.storagePid}
    }
    settings {
        showPageUid = {$plugin.tx_modernextbasefrontendedit.settings.showPageUid}
        editPageUid = {$plugin.tx_modernextbasefrontendedit.settings.editPageUid}
    }
}
');
