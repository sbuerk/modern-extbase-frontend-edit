<?php

declare(strict_types=1);

use SBUERK\ModernExtbaseFrontendEdit\Controller\ProfileAjaxController;
use SBUERK\ModernExtbaseFrontendEdit\Controller\ProfileController;
use SBUERK\ModernExtbaseFrontendEdit\Controller\ProfileEditController;
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

// The edit plugin: the markup the lit component enhances.
//
// It is registered as **non-cacheable**, and here that is not only the
// ownership argument the two read plugins make. The markup carries a request
// token signed with a *per browser* nonce, while the page cache identifier
// varies by frontend user group ids — a cached rendering would hand user B the
// token, and the profile, of user A. Extbase reads the list back in
// `Bootstrap::isExtbaseRequestCacheable()` and renders the plugin as USER_INT.
//
// The assets survive that: `f:asset.module` and `f:asset.css` are collected
// during the non-cached pass and rendered into the placeholders of the cached
// page by
// `PageRenderer::renderJavaScriptAndCssForProcessingOfUncachedContentObjects()`
// (`cms-frontend/Classes/Http/RequestHandler.php:300-307`), which re-runs the
// whole JavaScript and CSS rendering including the import map.
ExtensionUtility::configurePlugin(
    'ModernExtbaseFrontendEdit',
    'Edit',
    [
        ProfileEditController::class => 'edit',
    ],
    [
        ProfileEditController::class => 'edit',
    ],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT,
);

// The JSON endpoints of the edit plugin.
//
// This registration exists for one thing only: `registerControllerActions()`
// writes the controller/action allow list into
// $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extbase'], which is what the Extbase
// request builder validates an incoming controller and action against, and what
// `Bootstrap::isExtbaseRequestCacheable()` reads. The TypoScript this call
// generates alongside it — "tt_content.modernextbasefrontendedit_ajax =<
// lib.contentElement" — is deliberately **not** used by the endpoint page type
// below: `lib.contentElement` renders through the Fluid Styled Content "Generic"
// template, whose "Default" layout wraps the output in a
// "<div id=\"c{data.uid}\" class=\"frame …\">". That is exactly right for a
// content element and fatal for a JSON body, so the PAGE object calls
// EXTBASEPLUGIN directly.
//
// The plugin is not registered in "Configuration/TCA/Overrides/tt_content.php"
// either. It is an endpoint, not something an editor places on a page, and
// `configurePlugin()` adds no TCA by itself — the wizard entry comes from
// `registerPlugin()`, which is not called for it.
//
// **Every action is non-cacheable, and that is load-bearing twice.** The
// response depends on the logged-in frontend user, while the page cache
// identifier varies by frontend user *group* ids. And the request token that
// every write carries is signed with a per-browser nonce, so any markup or
// response tied to it must never be shared between users.
ExtensionUtility::configurePlugin(
    'ModernExtbaseFrontendEdit',
    'Ajax',
    [
        ProfileAjaxController::class => 'read,save,saveField,addChild,removeChild,reorderChildren,setChildVisibility',
    ],
    [
        ProfileAjaxController::class => 'read,save,saveField,addChild,removeChild,reorderChildren,setChildVisibility',
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
        # The page type ("&type=") of the JSON endpoints. It is a page *type*
        # and not a page uid: the endpoints answer on whichever page the edit
        # plugin sits on, so no separate page has to be created for them. Change
        # it only if the number collides with another extension on the site.
        ajaxPageType = 1589
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
        ajaxPageType = {$plugin.tx_modernextbasefrontendedit.settings.ajaxPageType}
    }
    view {
        # Lets the Extbase UriBuilder produce an endpoint URL with
        # ->setFormat(\'json\') as well as with ->setTargetPageType(),
        # `ExtensionService::getTargetPageTypeByFormat()` reads exactly this key
        # (`ExtensionService.php:237-244`). Both spellings then resolve to the
        # same page type, so the edit plugin cannot generate a URL that points
        # at a type nobody renders.
        formatToPageTypeMapping.json = {$plugin.tx_modernextbasefrontendedit.settings.ajaxPageType}
    }
}

# The endpoint page type.
#
# A PAGE object of its own rather than a plugin on a page: the response is a
# JSON document, and everything that would otherwise wrap it has to be switched
# off in one place.
#
# "10" is EXTBASEPLUGIN directly, **not** "tt_content.modernextbasefrontendedit_ajax".
# That object exists (`configurePlugin()` writes it) and is unusable here,
# because it inherits "lib.contentElement" and therefore renders through the
# Fluid Styled Content "Generic" template, whose layout wraps the content in a
# "frame" div.
modernextbasefrontendedit_ajax = PAGE
modernextbasefrontendedit_ajax {
    typeNum = {$plugin.tx_modernextbasefrontendedit.settings.ajaxPageType}
    config {
        # Returns the body content unchanged, skipping every PageRenderer
        # setting, so the response is exactly what the plugin produced
        # (`cms-frontend/Classes/Http/RequestHandler.php:258-262`). The
        # "Content-Type" the action set survives it: the Extbase bootstrap hands
        # it to PageParts (`cms-extbase/Classes/Core/Bootstrap.php:168-173`) and
        # RequestHandler writes it onto the response (`:1157`), after the
        # non-cached parts have been rendered (`:234-244`).
        disableAllHeaderCode = 1
        # No "Content-Language" header on a JSON document (`RequestHandler.php:1160-1162`).
        disableLanguageHeader = 1
        # Read by EXT:adminpanel, which is not a dependency of this extension.
        # It is set so that an installation which does have it does not inject
        # its markup into a JSON response for a logged-in backend user.
        admPanel = 0
        debug = 0
        # The endpoint page is never cached, and this is the only instrument
        # that acts early enough. It routes straight into the cache instruction
        # request attribute of Feature #102628 — the middleware calls
        # $cacheInstruction->disableCache() for it
        # (`cms-frontend/Classes/Middleware/PrepareTypoScriptFrontendRendering.php:261-264`)
        # — but it does so *before* page generation, whereas the plugin runs as
        # USER_INT and therefore after RequestHandler has already written the
        # page cache (`RequestHandler.php:174-226` vs. `:234-238`). A
        # disableCache() call from inside the action cannot prevent that write;
        # it is still made, because it does reach the client cache headers.
        #
        # The objection to "config.no_cache" is that it is a page-wide toggle.
        # Here the page *is* the endpoint, so page-wide is the intended scope.
        no_cache = 1
    }
    10 = EXTBASEPLUGIN
    10 {
        extensionName = ModernExtbaseFrontendEdit
        pluginName = Ajax
    }
}
');
