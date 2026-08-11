..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

The extension has to be installed like any other TYPO3 CMS extension.

Composer mode
=============

..  code-block:: bash

    composer require sbuerk/modern-extbase-frontend-edit

..  note::

    As long as no stable version has been released, the development version of
    the main branch has to be required explicitly:

    ..  code-block:: bash

        composer require sbuerk/modern-extbase-frontend-edit:^1.0@dev

    This additionally requires ``minimum-stability`` to be set to ``dev``
    together with ``prefer-stable`` set to ``true`` in the root
    :file:`composer.json` file.

Classic mode
============

#.  **Get it from the Extension Manager**:
    Switch to the module :guilabel:`Admin Tools > Extensions`, switch to
    :guilabel:`Get Extensions` and search for the extension key
    *modern_extbase_frontend_edit*, then import the extension from the repository.

#.  **Get it from typo3.org**:
    You can always get the current version from `TER`_ by downloading the zip
    version. Upload the file afterwards in the Extension Manager.

..  _TER: https://extensions.typo3.org/extension/modern_extbase_frontend_edit

Configuration
=============

The two profile plugins need a storage page before they render anything, and
the list plugin needs to know which page holds the detail plugin before it can
link to it.

Either add the site set :guilabel:`Profiles` to the site configuration and fill
in its settings, or set the equivalent TypoScript constants below
:typoscript:`plugin.tx_modernextbasefrontendedit`. Both spellings, and what
each setting does, are described in the changelog entry for the plugins.

There is no value meaning "every page": a storage page has to be named, or the
plugins query page zero and find nothing.
