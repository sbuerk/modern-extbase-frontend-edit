..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

Installing the extension is one command. Making it show anything takes four
more steps, because it edits records that belong to website users and neither
the records nor the users exist yet.

Requirements
============

*   TYPO3 v13.4 or v14.3
*   PHP 8.2 up to 8.5
*   A website user login on the site the editing plugin is placed on. This
    extension ships none — any frontend login solution will do.

No build step is required. The compiled JavaScript and CSS are part of the
package.

Install the package
===================

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

Set it up
=========

#.  **Create a storage folder** and configure it as the storage page. Profile
    records are read from it and written next to it. There is no value meaning
    "every page" — without one, the plugins query page zero and find nothing.

#.  **Place the plugins.** :guilabel:`Profiles: list` on a listing page,
    :guilabel:`Profiles: detail` on a detail page,
    :guilabel:`Profiles: edit` on the page a logged-in user edits their own
    profile on. Point the settings at the pages holding the detail and edit
    plugins, otherwise no links to them are rendered.

#.  **Create a profile record** and assign a website user as its owner. A
    profile with no owner is visible in the list and the detail view, and can
    never be edited in the frontend.

#.  **Check the upload limits** if profile images are wanted. The extension
    refuses an image above its own limit with a message; a file above the PHP
    or web server limit never reaches it, and the visitor sees the server's
    error instead. Keep :php:`upload_max_filesize` and :php:`post_max_size`
    above the extension's limit — see :ref:`reference-image-upload`.

:ref:`configuration` describes the settings, both spellings of them, and how to
override the templates.

Classic mode
============

The extension is developed and tested in composer mode. It carries an
:file:`ext_emconf.php` and has no composer-only dependency, so a classic mode
installation is expected to work, but it is not part of the test matrix.

Releases are published to the `TYPO3 Extension Repository
<https://extensions.typo3.org/extension/modern_extbase_frontend_edit>`__ under
the extension key ``modern_extbase_frontend_edit``. The archive uploaded there
holds the same files as the package composer installs — the developer
documentation, the test suites and the build tooling are in neither.
