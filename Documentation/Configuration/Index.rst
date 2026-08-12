..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The three plugins :guilabel:`Profiles: list`, :guilabel:`Profiles: detail` and
:guilabel:`Profiles: edit` share one set of configuration values. There is no
configuration per placement: an editor drops a plugin on a page, and what it
reads and where it links to is decided once, for the whole site.

Two ways to configure
=====================

The same five values exist in two spellings, and an installation uses whichever
fits how its sites are configured:

*   As **site settings** of the site set :guilabel:`Profiles` that this
    extension ships. Site sets are available since TYPO3 v13.1, and the values
    are then edited in the backend. See :ref:`configuration-site-set`.

*   As **TypoScript constants** below
    :typoscript:`plugin.tx_modernextbasefrontendedit`, for installations that
    configure their sites with :sql:`sys_template` records or with a site
    package's TypoScript. See :ref:`configuration-typoscript`.

Both carry the same defaults, and the classic TypoScript is registered in any
case. Adding the site set to a site is therefore an option, not a prerequisite —
but where it is added, its values win over the classic defaults.

What is mandatory
=================

Exactly one value has to be set. Everything else has a default that works.

..  important::

    The **storage page** has to name the page the profile records live on.

    There is no value meaning "all pages". An unconfigured plugin queries page
    id :code:`0`, profile records do not live on the root level, and the list
    stays empty as a result.

The remaining four values are optional in the sense that the extension renders
without them — but two of them decide whether a feature is reachable at all: no
detail page means no link from the list to a profile, and no edit page means no
edit link anywhere. Both are described with their defaults in
:ref:`configuration-settings`.

In this chapter
===============

..  toctree::
    :maxdepth: 1
    :titlesonly:

    SiteSet
    Settings
    TypoScript
    Templates
    Styling
