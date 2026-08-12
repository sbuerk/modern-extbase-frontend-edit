..  include:: /Includes.rst.txt

..  _configuration-typoscript:

==========
TypoScript
==========

This page describes the classic path — an installation that configures its sites
with :sql:`sys_template` records or with a site package's TypoScript instead of
with site sets — and the TypoScript this extension registers by itself, which
applies to every installation either way.

Nothing has to be included
==========================

There is no static template to add and no include to configure. The extension
registers its constants and its setup as **default TypoScript** from
:file:`ext_localconf.php`, so both are part of every site of the installation
from the moment the extension is installed.

Configuring the plugins therefore means overriding constants that already exist:

..  code-block:: typoscript
    :caption: Constants of a sys_template record or of a site package

    plugin.tx_modernextbasefrontendedit {
        persistence.storagePid = 42
        settings.showPageUid = 43
        settings.editPageUid = 44
    }

The constants
=============

These are the constants the extension declares, with the defaults it ships:

..  code-block:: typoscript

    plugin.tx_modernextbasefrontendedit {
        persistence {
            storagePid = 0
        }
        settings {
            showPageUid = 0
            editPageUid = 0
            ajaxPageType = 1589
            imageUploadFolder = 1:/user_upload/profiles/
        }
    }

What each one means, and how it is spelled as a site setting, is listed in
:ref:`configuration-settings`.

The constants are registered for the :typoscript:`siteSets` scope as well, which
is what makes them the *defaults* on a site that uses the site set: that scope is
included before the sets of a site, so the set's own values are layered on top.

The setup
=========

Error handling
--------------

..  code-block:: typoscript

    plugin.tx_modernextbasefrontendedit.mvc {
        showPageNotFoundIfTargetNotFoundException = 1
        showPageNotFoundIfRequiredArgumentIsMissingException = 1
    }

A request for the detail plugin naming a profile that does not exist, or that
the visitor may not see, answers with the site's configured 404 page instead of
an exception page. The same applies when the profile argument is missing
entirely.

The plugin settings
-------------------

The setup maps every constant onto the configuration the plugins read:

..  code-block:: typoscript

    plugin.tx_modernextbasefrontendedit {
        persistence.storagePid = {$plugin.tx_modernextbasefrontendedit.persistence.storagePid}
        settings {
            showPageUid = {$plugin.tx_modernextbasefrontendedit.settings.showPageUid}
            editPageUid = {$plugin.tx_modernextbasefrontendedit.settings.editPageUid}
            ajaxPageType = {$plugin.tx_modernextbasefrontendedit.settings.ajaxPageType}
            imageUploadFolder = {$plugin.tx_modernextbasefrontendedit.settings.imageUploadFolder}
        }
    }

Setting these directly, rather than the constants, works and is what the site
set does for a site that uses it. For everything else, override the constant —
it is the one place all three consumers of the endpoint page type are fed from.

The format mapping
------------------

..  code-block:: typoscript

    plugin.tx_modernextbasefrontendedit.view.formatToPageTypeMapping.json = {$plugin.tx_modernextbasefrontendedit.settings.ajaxPageType}

Extbase reads this key when a URI is built with the format :code:`json`. It is
fed from the same constant as :typoscript:`settings.ajaxPageType`, so both
spellings of an endpoint address resolve to the same page type and a URL can
never point at a type nobody renders. Change the page type in one place — the
constant — and both follow.

The endpoint page object
========================

The JSON endpoints of the edit plugin answer on a page type of their own, and
that page type is a :typoscript:`PAGE` object:

..  code-block:: typoscript

    modernextbasefrontendedit_ajax = PAGE
    modernextbasefrontendedit_ajax {
        typeNum = {$plugin.tx_modernextbasefrontendedit.settings.ajaxPageType}
        config {
            disableAllHeaderCode = 1
            disableLanguageHeader = 1
            admPanel = 0
            debug = 0
            no_cache = 1
        }
        10 = EXTBASEPLUGIN
        10 {
            extensionName = ModernExtbaseFrontendEdit
            pluginName = Ajax
        }
    }

It is a page *type*, not a page: the endpoints answer on whichever page the edit
plugin sits on, so no separate page has to be created for them. The object is
registered from :file:`ext_localconf.php` and therefore exists on every site; a
site using the site set only restates its :typoscript:`typeNum`, because a site
setting cannot reach a TypoScript constant.

The endpoints are not a content element. They are not offered in the content
element wizard, and an editor cannot place them on a page.

What must not be overridden
===========================

Every line of that object is load bearing. In particular:

..  warning::

    :typoscript:`config.no_cache = 1` is **required**, not a default worth
    trimming. Without it TYPO3 writes a page cache entry for the endpoint — an
    entry shared by every website user in the same user group, because the page
    cache distinguishes visitors by their groups and not by their user record.
    One user would then be served another user's profile.

:typoscript:`config.disableAllHeaderCode = 1`
    Returns the body content unchanged and skips the whole page renderer, which
    is what makes the response the exact JSON document the endpoint produced.
    Without it the JSON is wrapped in an HTML document.

:typoscript:`config.disableLanguageHeader = 1`
    Suppresses the :code:`Content-Language` header, which has no meaning on a
    JSON document.

:typoscript:`config.admPanel = 0`
    Keeps the admin panel — where :file:`EXT:adminpanel` is installed, which
    this extension does not require — from injecting its markup into a JSON
    response for a logged-in backend user.

:typoscript:`10 = EXTBASEPLUGIN`
    The plugin is called directly. It must **not** be replaced with
    :typoscript:`tt_content.modernextbasefrontendedit_ajax`, although that
    object exists: it inherits :typoscript:`lib.contentElement` and renders
    through the Fluid Styled Content generic template, whose layout wraps the
    output in a :html:`<div class="frame …">`. That is right for a content
    element and fatal for a JSON body.

Overriding :typoscript:`typeNum` on its own is the one change that looks
harmless and is not: it has to keep matching
:typoscript:`settings.ajaxPageType` and
:typoscript:`view.formatToPageTypeMapping.json`, which is exactly why all three
are fed from one constant. Change the constant instead.
