..  include:: /Includes.rst.txt

..  _configuration-templates:

====================
Templates and labels
====================

The templates of this extension are deliberately small and unstyled. Every
concept — the card, the details, the address list, the e-mail list, the image,
the edit link — is a partial of its own, so a single one can be replaced without
copying the rest.

Overriding templates
====================

The extension registers **no** :typoscript:`templateRootPaths`,
:typoscript:`partialRootPaths` or :typoscript:`layoutRootPaths` of its own. It
does not have to: Extbase prepends the convention paths
:file:`EXT:modern_extbase_frontend_edit/Resources/Private/{Templates,Layouts,Partials}/`
to whatever is configured, and Fluid searches the configured paths from the
highest key downwards. An own path therefore wins outright, and nothing has to
be re-declared to keep the shipped files reachable as a fallback:

..  code-block:: typoscript

    plugin.tx_modernextbasefrontendedit.view {
        templateRootPaths.10 = EXT:my_site_package/Resources/Private/Extensions/ModernExtbaseFrontendEdit/Templates/
        partialRootPaths.10 = EXT:my_site_package/Resources/Private/Extensions/ModernExtbaseFrontendEdit/Partials/
        layoutRootPaths.10 = EXT:my_site_package/Resources/Private/Extensions/ModernExtbaseFrontendEdit/Layouts/
    }

A file that is not present in the own path falls back to the one the extension
ships, so overriding a single partial means placing a single file.

Per plugin
----------

The paths can also be narrowed to one plugin, by using the plugin signature.
Configuration below :typoscript:`plugin.tx_modernextbasefrontendedit_<plugin>`
is merged over :typoscript:`plugin.tx_modernextbasefrontendedit`, with
:code:`list`, :code:`show` and :code:`edit` as the plugin names:

..  code-block:: typoscript

    plugin.tx_modernextbasefrontendedit_edit.view.partialRootPaths.10 = EXT:my_site_package/Resources/Private/EditOnly/Partials/

What there is to override
=========================

The paths below are relative to
:file:`EXT:modern_extbase_frontend_edit/Resources/Private/`. A replacement has
to keep the same relative file name, because that is what Fluid resolves.

..  list-table::
    :header-rows: 1

    *   -   File
        -   Renders

    *   -   :file:`Layouts/Default.html`
        -   The single wrapper element of all three plugins, with one
            :code:`Main` section. The one stable hook to style or script
            against.

    *   -   :file:`Templates/Profile/List.html`
        -   The :guilabel:`Profiles: list` plugin: its heading, the empty state,
            and one card per profile. Both links a card can carry are built
            here, because only a template knows which plugin it links to.

    *   -   :file:`Templates/Profile/Show.html`
        -   The :guilabel:`Profiles: detail` plugin: the card, the details, the
            addresses and the e-mail addresses of one profile.

    *   -   :file:`Templates/ProfileEdit/Edit.html`
        -   The :guilabel:`Profiles: edit` plugin, in all four of its states:
            not logged in, logged in without a profile, a profile in a
            workspace, and the editable profile.

    *   -   :file:`Partials/Profile/Card.html`
        -   The identifying block of a profile — image, name, and the links that
            apply to it. Rendered by the list once per entry and by the detail
            view as its head.

    *   -   :file:`Partials/Profile/Details.html`
        -   The scalar fields that are not part of the card: birthday and
            biography. Renders nothing when both are empty.

    *   -   :file:`Partials/Profile/AddressList.html`
        -   The postal addresses including their section heading. Renders
            nothing when there is none.

    *   -   :file:`Partials/Profile/EmailList.html`
        -   The e-mail addresses including their section heading, each as a
            mail link that honours the installation's spam protection setting.

    *   -   :file:`Partials/Profile/EditLink.html`
        -   The link to the edit page, and only for a profile the logged-in
            website user owns.

    *   -   :file:`Partials/Profile/Image.html`
        -   The profile image, or nothing when there is none.

    *   -   :file:`Partials/Profile/OwnerView.html`
        -   One profile as its owner sees it: name, image, details and both
            collections, hidden records included. The edit plugin renders it in
            both of its record states.

..  tip::

    :file:`Partials/Profile/Image.html` is the one most installations will want
    to replace. It writes a plain :html:`<img>` tag from the stored file and
    applies no image processing — an installation that wants cropped or scaled
    portraits replaces this partial and nothing else.

Why the Fluid layer is cut this way — which partial receives which arguments,
why URIs are built in templates and never in partials, and why the edit plugin
renders its record body through a partial twice — is written down for developers
in the repository, in :file:`docs/frontend-edit/plugins-and-fluid.md`. That
directory is not part of the shipped documentation.

..  note::

    Hiding the edit link is a **display** decision, not an access restriction.
    A template that renders it unconditionally does not grant anyone anything:
    the boundary is enforced by the editing endpoints. A template that never
    renders it does not protect anything either.

Language files
==============

Every string the plugins render comes from an XLIFF file, and both files are
overridden with the usual TYPO3 mechanism:

..  list-table::
    :header-rows: 1

    *   -   File
        -   Contains

    *   -   :file:`Resources/Private/Language/locallang.xlf`
        -   The names and descriptions of the three plugins in the content
            element wizard, every string the templates render, and every string
            the editing surface renders — its field labels, buttons, section
            headings, validation messages and error messages.

    *   -   :file:`Resources/Private/Language/locallang_db.xlf`
        -   The backend labels: table names, field labels and the item labels of
            the two :guilabel:`type` selections.

..  code-block:: php
    :caption: config/system/additional.php (TYPO3 v14)

    $GLOBALS['TYPO3_CONF_VARS']['LANG']['resourceOverrides']['EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf'][]
        = 'EXT:my_site_package/Resources/Private/Language/Overrides/locallang.xlf';

On TYPO3 v13 the same array is spelled
:php:`$GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride']`. It was renamed
to :php:`$GLOBALS['TYPO3_CONF_VARS']['LANG']['resourceOverrides']` in TYPO3 v14
— see the changelog entry for issue #107436 — and an existing configuration is
migrated automatically.

The type labels exist twice
---------------------------

..  warning::

    The item labels of the address and e-mail :guilabel:`type` selections exist
    in **both** language files:

    *   :file:`locallang_db.xlf`, keyed
        :code:`tx_modernextbasefrontendedit_domain_model_address.type.<value>`
        and :code:`…_email.type.<value>`, is what the backend form and the read
        plugins use. The list partials look the label up dynamically from the
        stored value.
    *   :file:`locallang.xlf`, keyed :code:`choice.address.type.<value>` and
        :code:`choice.email.type.<value>`, is what the editing surface uses.
        Those strings reach the component as data and cannot be looked up from
        a template.

    Overriding one file and not the other makes the same stored value read
    differently in the profile display and in the editing surface. **Override
    both, or neither.**

    The affected values are :code:`home`, :code:`work` and :code:`others` for an
    address, and :code:`private`, :code:`business` and :code:`others` for an
    e-mail address.
