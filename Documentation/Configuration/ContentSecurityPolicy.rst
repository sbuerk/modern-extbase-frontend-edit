..  include:: /Includes.rst.txt

..  _configuration-csp:

=======================
Content Security Policy
=======================

The extension declares the Content Security Policy sources its editing surface
needs, in :file:`Configuration/ContentSecurityPolicies.php`. TYPO3 collects that
file automatically from every installed package — there is nothing to register,
enable or copy.

Everything it asks for is the site's **own origin**. The extension loads no
script, no stylesheet, no font and no image from anywhere else, and it requests
no source expression that weakens a policy.

..  contents::
    :local:
    :depth: 1

Whether the policy applies at all
=================================

Frontend Content Security Policy is **switched off by default** in TYPO3 v13 and
v14. On an installation that has not enabled it, this file is collected and has
no effect, and the editing surface behaves as if it were not there.

There are two independent ways a site turns it on, and neither is implied by the
other.

..  code-block:: php
    :caption: config/system/additional.php

    // Send the policy as an enforcing header:
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.frontend.enforceContentSecurityPolicy'] = true;

    // Or only observe it, and collect violation reports:
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.frontend.reportContentSecurityPolicy'] = true;

..  code-block:: yaml
    :caption: config/sites/<identifier>/csp.yaml

    enforce: true

The :file:`csp.yaml` route needs **no feature flag**. A site can therefore have
the policy active while both flags are `false`, which is why "the feature is
off" is not a safe assumption when debugging a blocked resource.

What the extension declares
===========================

Four directives, each granting :code:`'self'` and nothing else:

..  list-table::
    :header-rows: 1

    *   -   Directive
        -   Needed for
    *   -   :code:`script-src 'self'`
        -   The editing module, an ES module resolved through the TYPO3 import
            map. No inline script is emitted by this extension.
    *   -   :code:`style-src 'self'`
        -   The one :html:`<link rel="stylesheet">` the plugin adds. The
            component's own styles never reach this directive — see below.
    *   -   :code:`connect-src 'self'`
        -   The :code:`fetch()` calls to the editing endpoints, at relative URLs
            built on the server.
    *   -   :code:`img-src 'self'`
        -   Stored profile images, served by the file abstraction layer from
            this origin.

All four use the :php:`Extend` mutation mode, which inherits whatever the
ancestor directive already permits before appending.

What they cost on a default installation
========================================

Close to nothing, and this was measured rather than argued: the same page was
rendered with the file and without it, and the emitted header differed by one
directive.

All four descend from :code:`default-src`. With the :code:`default-src 'self'`
that TYPO3 itself declares for the frontend, each of them resolves to exactly
:code:`'self'` — and TYPO3 then removes a directive whose source set is identical
to its ancestor's. Three of the four disappear that way. The fourth,
:code:`style-src`, survives only because the reporting token
:code:`'report-sample'` is appended to declared directives and not to
:code:`default-src`, so the two sets differ by a token that grants nothing.

..  note::

    What the declaration buys is the case where that stops being true. A site
    that **narrows** :code:`default-src` — to :code:`'none'`, or to a specific
    host — applies its own mutations after the ones packages declare. All four
    then stop being identical to their ancestor and survive into the header, and
    the editing surface keeps working instead of failing with console errors and
    no explanation.

What is deliberately not requested
==================================

Each of these was checked against the shipped assets. None is omitted by
oversight, and none should be added without establishing that it is needed.

..  list-table::
    :header-rows: 1

    *   -   Not requested
        -   Why it is not needed
    *   -   :code:`style-src 'unsafe-inline'`
        -   The component installs its styles through
            :js:`adoptedStyleSheets`, which produces no :html:`<style>` element
            at all.
    *   -   :code:`img-src data:` / :code:`blob:`
        -   There is no client side image preview. The chosen file goes straight
            into a :js:`FormData`, and what is shown afterwards is the stored
            file.
    *   -   :code:`form-action`
        -   There is no :html:`<form>`. Every control is a
            :html:`<button type="button">` and every write is a :js:`fetch()`.
    *   -   :code:`font-src`
        -   The surface ships no font and uses the page's typeface. See
            :ref:`configuration-styling-typeface`.
    *   -   :code:`script-src 'unsafe-eval'`, :code:`worker-src`,
            :code:`frame-src`, :code:`base-uri`, :code:`object-src`
        -   Nothing in the shipped assets uses any of them.

No nonce is requested either. The single inline script on the page is the import
map, which is TYPO3's own and which TYPO3 covers with a hash of its content.

..  warning::

    A **web font from another origin will not load** while the policy is active,
    and neither will any other third-party resource added to the editing
    surface. That is intended. Serve the file from the installation instead, or
    add the source to the site's own mutations — see below.

Changing or removing it
=======================

Dropping only this extension
----------------------------

Name the composer package in the site's :file:`csp.yaml`. Everything other
packages declare is kept:

..  code-block:: yaml
    :caption: config/sites/<identifier>/csp.yaml

    enforce:
      packages:
        '*': true
        sbuerk/modern-extbase-frontend-edit: false

Disabling the policy for a site
-------------------------------

..  code-block:: yaml
    :caption: config/sites/<identifier>/csp.yaml

    active: false

Adding sources of your own
--------------------------

Site level mutations are applied **after** those of every package, so they are
the place to widen or narrow a directive:

..  code-block:: yaml
    :caption: config/sites/<identifier>/csp.yaml

    enforce:
      mutations:
        -   mode: extend
            directive: img-src
            sources:
                - 'https://images.example.org'

..  warning::

    :yaml:`inheritDefault: false` is **not** a way to drop only this extension.
    It removes the frontend rules of *every* package, the :code:`default-src
    'self'` that TYPO3 itself declares included, and a site using it has to grant
    the four sources above by hand or the editing surface stops working.

Further reading
===============

The complete :file:`csp.yaml` format, the reporting endpoint and the full list
of mutation modes are documented by TYPO3 itself:

*   `Content Security Policy
    <https://docs.typo3.org/permalink/t3coreapi:content-security-policy>`__ in
    the TYPO3 Explained manual.

The reasoning behind each of the four declarations, including what was measured
and what was rejected, is in the docblock of
:file:`Configuration/ContentSecurityPolicies.php` — it is written to be read.
