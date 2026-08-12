..  include:: /Includes.rst.txt

..  _feature-content-security-policy:

======================================
Feature: Content Security Policy rules
======================================

Description
===========

The extension now declares the Content Security Policy sources its frontend
editing surface needs, in
:file:`Configuration/ContentSecurityPolicies.php`. Nothing has to be enabled for
it: TYPO3 picks the file up automatically for every installation that has
frontend CSP switched on, and installations that have not are unaffected.

Frontend CSP is **off by default** in TYPO3 v13 and v14. A site enables it
either with a feature flag:

..  code-block:: php
    :caption: config/system/additional.php

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['security.frontend.enforceContentSecurityPolicy'] = true;

or, without any feature flag, per site:

..  code-block:: yaml
    :caption: config/sites/<identifier>/csp.yaml

    enforce: true

What is declared
================

Four directives, each granting only the site's own origin:

..  list-table::
    :header-rows: 1

    -   -   Directive
        -   Needed for
    -   -   :code:`script-src 'self'`
        -   The editing module, loaded through the TYPO3 import map.
    -   -   :code:`style-src 'self'`
        -   The stylesheet of the editing surface.
    -   -   :code:`connect-src 'self'`
        -   The :code:`fetch()` requests to the editing endpoints.
    -   -   :code:`img-src 'self'`
        -   Profile images, served from the file storage.

Nothing is loaded from an external origin, and no directive that would weaken a
policy is requested — in particular no :code:`'unsafe-inline'`, no
:code:`'unsafe-eval'`, and no :code:`data:` or :code:`blob:` image sources.

Under the policy TYPO3 itself ships for the frontend, these four add almost
nothing to the emitted header, because they permit what :code:`default-src
'self'` already permits. They matter on an installation that narrows
:code:`default-src`, where they are what keeps the editing surface working.

Switching it off or changing it
===============================

To keep the policy of every other extension but drop this one, name the composer
package in the site's :file:`csp.yaml`:

..  code-block:: yaml
    :caption: config/sites/<identifier>/csp.yaml

    enforce:
      packages:
        '*': true
        sbuerk/modern-extbase-frontend-edit: false

To disable Content Security Policy for a site entirely:

..  code-block:: yaml
    :caption: config/sites/<identifier>/csp.yaml

    active: false

..  warning::

    :yaml:`inheritDefault: false` is **not** a way to drop only this extension.
    It removes the frontend rules of *every* package, including the
    :code:`default-src 'self'` that TYPO3 itself declares, and a site using it
    has to grant the four sources above by hand or the editing surface stops
    working.

The full set of options — reporting, per site mutations, and the
:file:`csp.yaml` format — is described in the TYPO3 documentation:
`Content Security Policy
<https://docs.typo3.org/permalink/t3coreapi:content-security-policy>`__.
