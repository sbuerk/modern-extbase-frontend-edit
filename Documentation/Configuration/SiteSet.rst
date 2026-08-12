..  include:: /Includes.rst.txt

..  _configuration-site-set:

========
Site set
========

The extension ships one site set. Its label is :guilabel:`Profiles`, its
identifier is :yaml:`sbuerk/modern-extbase-frontend-edit` — the composer package
name, which is the convention for a set identifier — and it is defined in
:file:`Configuration/Sets/Profiles/config.yaml`.

The set does two things: it declares the five settings of the plugins, so they
become editable in the backend, and it maps those settings onto the Extbase
plugin configuration in its :file:`setup.typoscript`. It declares no rendering
definitions and no templates.

Adding the set to a site
========================

In the site configuration
-------------------------

This is the way for a site without a site package. Add the set identifier to
the site's dependencies:

..  code-block:: yaml
    :caption: config/sites/<identifier>/config.yaml

    base: https://example.com
    dependencies:
      - sbuerk/modern-extbase-frontend-edit

In a site package
-----------------

If the installation has a site package with a site set of its own, let that set
depend on this one instead. The site then only names the site package's set:

..  code-block:: yaml
    :caption: EXT:my_site_package/Configuration/Sets/MySet/config.yaml

    name: myvendor/my-site-package
    label: My site package
    dependencies:
      - sbuerk/modern-extbase-frontend-edit

Editing the settings
====================

Once the set is part of a site, its settings appear in the settings editor of
that site, grouped under the category :guilabel:`Profiles`.

*   On TYPO3 v13 the editor is the module
    :guilabel:`Site Management` > :guilabel:`Settings`.
*   On TYPO3 v14 the backend modules were renamed and restructured
    (issue #107628): the top level module is called :guilabel:`Sites`, the
    former :guilabel:`Settings` module was merged into
    :guilabel:`Sites` > :guilabel:`Setup`, and the settings are edited from the
    site itself.

Saving writes the values to :file:`config/sites/<identifier>/settings.yaml`, so
they can equally be written there by hand:

..  code-block:: yaml
    :caption: config/sites/<identifier>/settings.yaml

    modernextbasefrontendedit:
      persistence:
        storagePid: '42'
      showPageUid: 43
      editPageUid: 44

The two page settings are declared as :yaml:`type: page` and are offered with a
page picker in the editor. The storage page is declared as :yaml:`type: string`
on purpose: it accepts a comma separated list of page uids, which a page picker
could not express.

Every key, its default and its meaning is listed in
:ref:`configuration-settings`.

The set is optional
===================

A site that does not use the set is fully supported. The classic TypoScript
constants that :file:`ext_localconf.php` registers ship alongside the set and
carry exactly the same defaults, so nothing is missing without it — see
:ref:`configuration-typoscript`.

Where the set *is* used, the order is settled and not accidental: the classic
defaults are also registered in the :typoscript:`siteSets` scope, which TYPO3
includes before the sets of a site. The set's :file:`setup.typoscript` is
therefore layered on top and its values win.

..  note::

    Only the settings are duplicated this way, not the rest of the TypoScript.
    The error handling of the plugins and the page object of the editing
    endpoints are defined once, in :file:`ext_localconf.php`, and apply to every
    site whether it uses the set or not.
