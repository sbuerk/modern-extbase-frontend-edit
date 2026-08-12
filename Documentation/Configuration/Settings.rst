..  include:: /Includes.rst.txt

..  _configuration-settings:

========
Settings
========

Five values configure the three plugins. This page lists all of them, with the
default each one ships with.

Settings of the site set
========================

These are the settings the site set :guilabel:`Profiles` declares. They are
edited in the settings editor of a site that uses the set — see
:ref:`configuration-site-set` — or written to
:file:`config/sites/<identifier>/settings.yaml` by hand.

..  typo3:site-set-settings:: PROJECT:/Configuration/Sets/Profiles/settings.definitions.yaml
    :name: modern-extbase-frontend-edit
    :type:
    :Label: Settings of the profile plugins

The equivalent TypoScript constants
===================================

An installation that does not use site sets configures the same values as
TypoScript constants. The two spellings differ — the site setting is a flat,
dotted key, the constant sits below the Extbase plugin namespace — so they are
listed side by side:

..  list-table::
    :header-rows: 1

    *   -   Site setting
        -   TypoScript constant
        -   Default

    *   -   :yaml:`modernextbasefrontendedit.persistence.storagePid`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.persistence.storagePid`
        -   :code:`0`

    *   -   :yaml:`modernextbasefrontendedit.showPageUid`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.settings.showPageUid`
        -   :code:`0`

    *   -   :yaml:`modernextbasefrontendedit.editPageUid`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.settings.editPageUid`
        -   :code:`0`

    *   -   :yaml:`modernextbasefrontendedit.ajaxPageType`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.settings.ajaxPageType`
        -   :code:`1589`

    *   -   :yaml:`modernextbasefrontendedit.imageUploadFolder`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.settings.imageUploadFolder`
        -   :code:`1:/user_upload/profiles/`

Note that only the storage page keeps its :typoscript:`persistence` level in the
constant. The other four are Extbase plugin *settings* and therefore live below
:typoscript:`settings`, while the storage page is framework configuration that
Extbase reads from :typoscript:`persistence`.

Behaviour to be aware of
========================

Two of these values behave in a way that a default does not suggest, and both
are worth knowing before a page is built around them.

..  important::

    **There is no value meaning "all pages" for the storage page.**

    An empty or unset storage page is not "search everywhere". Extbase turns
    the empty configuration into the page id list :code:`0`, profile records do
    not live on the root level, and the list plugin therefore renders its empty
    state. The value has to name the page — or the comma separated pages — the
    records are stored on.

..  warning::

    **An endpoint page type of** :code:`0` **makes the edit plugin read only.**

    The plugin builds one finished URL per editing endpoint from this page
    type, and it builds none while the value is :code:`0`. The editing
    component is not activated without that map, and what stays on the page is
    the server rendered profile: readable, complete, and not editable.

    That is a deliberate failure mode rather than an error — every button would
    otherwise call an address that answers with an HTML page instead of JSON —
    but it does mean a value of :code:`0` silently turns editing off. Change
    the page type only when the number :code:`1589` collides with another
    extension on the same site, and change it to another free number, never to
    :code:`0`.

The image upload folder is a **combined storage identifier**: a storage number,
a colon, and a folder path, as in :code:`1:/user_upload/profiles/`. Any other
shape is refused by the upload configuration, and the endpoint then answers with
an error instead of JSON. The folder itself is created on the first upload; the
storage it names has to exist. An empty value falls back to the same default.
