..  include:: /Includes.rst.txt

..  _reference-image-upload:

============
Image upload
============

A profile carries at most one image. It is written by two endpoints of its own,
:code:`uploadImage` and :code:`removeImage`, and never by a save that carries
the other fields.

The two endpoints
=================

..  list-table::
    :header-rows: 1

    -   -   Endpoint
        -   Request
    -   -   :code:`uploadImage`
        -   :code:`POST` with a :code:`multipart/form-data` body. The file
            travels in the field
            :code:`tx_modernextbasefrontendedit_ajax[profile][image]`, the uid
            of the addressed record in
            :code:`tx_modernextbasefrontendedit_ajax[uid]`.
    -   -   :code:`removeImage`
        -   :code:`POST` with a JSON body, like every other endpoint. Removing
            an image that is already absent is not an error.

Both require the request token, a logged-in website user and the live
workspace, and both answer with the same document every other endpoint answers
with — see :ref:`reference-endpoints`.

Exactly one file per request. A request carrying more than one part for the
image field is refused with :code:`400` and the error code :code:`1786496006`.
A request carrying none is refused with :code:`422`, keyed under the field name
:code:`image`.

What is accepted
================

..  list-table::
    :header-rows: 1

    -   -   Rule
        -   Value
        -   Message id
    -   -   MIME type
        -   :code:`image/jpeg`, :code:`image/png`, :code:`image/gif`,
            :code:`image/webp`
        -   :code:`validation.profile.image.mimeType`
    -   -   File name extension
        -   Has to match the detected MIME type
        -   :code:`validation.profile.image.extension`
    -   -   File size
        -   At most :code:`5M`
        -   :code:`validation.profile.image.tooLarge`
    -   -   Width
        -   At most 5000 pixels. No lower bound is configured.
        -   :code:`validation.profile.image.tooWide`
    -   -   Height
        -   At most 5000 pixels. No lower bound is configured.
        -   :code:`validation.profile.image.tooTall`

:code:`image/svg+xml` is deliberately absent from the accepted MIME types.

Two further checks are added by TYPO3 itself and cannot be switched off from
here: the file name check, and the consistency check between the file name
extension and the detected MIME type. Their messages come from
:file:`EXT:extbase/Resources/Private/Language/locallang.xlf` and are not
overridden by this extension.

A rejected upload stores nothing at all — no partial file and no temporary
copy. The file has to be chosen again.

Where the files are stored
==========================

One setting decides the target folder.

..  list-table::
    :header-rows: 1

    -   -   Setting
        -   TypoScript constant
        -   Default
    -   -   :yaml:`modernextbasefrontendedit.imageUploadFolder`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.settings.imageUploadFolder`
        -   :code:`1:/user_upload/profiles/`

The value has to be a **combined storage identifier** — a storage uid, a colon
and a folder path, as in :code:`1:/user_upload/profiles/`. Anything else is
refused by the Extbase upload configuration with exception code
:code:`1711801071`, and the endpoint then answers an exception page instead of
JSON. An empty or whitespace-only value falls back to the same default, which
is also what applies when no TypoScript reaches the plugin at all.

The folder itself does not have to exist; it is created on the first upload.
The storage it names does have to exist.

Uploaded files keep their name and receive a random suffix, and a name that
already exists in the target folder is renamed rather than overwritten.

Replacing and removing an image
===============================

Storing a new image does not overwrite the file behind the old one — the new
file is written and the reference is repointed at it. The extension then
deletes the previous file, **but only when nothing else in the installation
still points at it**. Two sources are consulted, and either one of them keeps
the file:

..  list-table::
    :header-rows: 1

    -   -   Source
        -   Counted
    -   -   :sql:`sys_file_reference`
        -   Every row that is not deleted and points at the file, excluding the
            profile's own reference. A hidden reference counts — the record it
            belongs to still owns the file.
    -   -   :sql:`sys_refindex`
        -   Every entry with :sql:`ref_table = 'sys_file'` naming the file,
            excluding the entry for the profile's own reference. This is what
            catches usages that no :sql:`sys_file_reference` row covers, such
            as a :code:`t3://file` link in rich text.

:guilabel:`Remove` follows the same path: the reference is cleared, its
:sql:`sys_file_reference` row is soft deleted, and the file is deleted under
the same condition.

..  note::

    Whether the deletion frees disk space depends on the storage. A storage
    with a recycler folder receives the file instead of removing it. The
    :sql:`sys_file` record is gone in both cases.

Limits outside this extension
=============================

The :code:`5M` bound applies to requests that reach TYPO3. Two limits cut in
before that and produce the web server's own answer rather than a JSON body:

..  list-table::
    :header-rows: 1

    -   -   Limit
        -   Where
    -   -   :php:`upload_max_filesize`
        -   PHP configuration. A larger file never reaches :php:`$_FILES`.
    -   -   :php:`post_max_size`
        -   PHP configuration. It bounds the whole request body, which is the
            file plus the other multipart fields, so it has to be the larger of
            the two.
    -   -   Request body size limit
        -   Web server or reverse proxy, for example
            :code:`client_max_body_size` in nginx.

Keep all three at or above 5 MB, or lower the extension's own limit to match
them, so that the answer a visitor receives is the one this extension produced.
