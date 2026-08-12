..  include:: /Includes.rst.txt

..  _feature-profile-image-upload:

=============================
Feature: Profile image upload
=============================

Description
===========

A website user can now set, replace and remove their own profile image from the
frontend. The image is part of the editing surface of the :guilabel:`Profile
edit` plugin: picking a file uploads it immediately, the stored image is shown
in its place as soon as the server has accepted it, and :guilabel:`Remove`
clears it again.

There is no separate save step for the image, and no cropping or preview stage
in between. A file is either stored or refused, and the answer arrives without
the page reloading.

Two endpoints were added for it, which brings the endpoint set of the extension
to nine:

..  list-table::
    :header-rows: 1

    *   -   Endpoint
        -   What it does

    *   -   :code:`uploadImage`
        -   Stores the uploaded file as the profile image, replacing the
            previous one if there was one.

    *   -   :code:`removeImage`
        -   Removes the profile image. Removing an image that is already absent
            is not an error.

Both answer with the whole profile as it stands after the write, exactly like
the seven endpoints of :ref:`feature-profile-editing-endpoints`, and both apply
the same security rules: a valid request token, a logged-in website user, no
active workspace, and a record resolved from the session rather than from the
request.

:code:`uploadImage` is the one endpoint of this extension that does not take a
JSON request body. A file cannot travel in a JSON document without being
re-encoded, which inflates the request and holds the file in memory twice, so
the upload is an ordinary file upload request. Everything else about it is
unchanged.

Without JavaScript the profile image is still rendered, and it is still
readable — it simply cannot be changed, like every other field of the editing
surface.

Configuration
=============

One setting decides where uploaded images are stored. It is available as a site
setting of the site set :guilabel:`Profiles` shipped by this extension, and as a
TypoScript constant for installations that configure their sites with
:sql:`sys_template` records.

..  list-table::
    :header-rows: 1

    *   -   Setting
        -   TypoScript constant
        -   Meaning

    *   -   :yaml:`modernextbasefrontendedit.imageUploadFolder`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.settings.imageUploadFolder`
        -   The folder uploaded profile images are written to. Defaults to
            :code:`1:/user_upload/profiles/`.

The value is a **combined storage identifier** — a storage number, a colon and a
folder path — and not a file system path. A value in any other shape is refused
outright, with an error naming the setting. The folder itself is created on the
first upload and does not have to exist beforehand; the storage it names does.

Point the setting at a folder of its own rather than at a shared one. Uploaded
portraits arrive with an unguessable name and mixed with other editorial files
they are hard to tell apart later.

Accepted files
==============

Every upload is checked before anything is written, and a file that fails any of
these checks is refused with a message shown at the image:

..  list-table::
    :header-rows: 1

    *   -   Rule
        -   Value

    *   -   File formats
        -   JPEG, PNG, GIF and WebP.

    *   -   File size
        -   At most 5 MB.

    *   -   Dimensions
        -   At most 5000 pixels on either edge. There is no minimum.

SVG is deliberately not accepted. An SVG file is a document a browser executes,
and accepting one as a portrait would let a website user store code that runs
for every visitor who looks at the profile.

..  important::

    The size limit only applies to requests that reach TYPO3 at all. PHP's own
    :php:`upload_max_filesize` and :php:`post_max_size`, and any request body
    limit configured in the web server, cut a larger request off earlier — and
    the visitor then sees the web server's answer instead of ours. Keep those
    limits at or above 5 MB, or lower the setting to match them.

A refused upload stores **nothing**: no partial file, no temporary copy, nothing
to clean up. The consequence is one a visitor notices, so the surface says it —
after a refusal the file has to be picked again, because nothing was kept.

The file behind a replaced image
================================

Replacing an image stores the new file and leaves the previous one behind;
TYPO3 does not collect those by itself. This extension removes it — but only
when nothing else in the installation still refers to it.

That condition is not politeness. Deleting a file record in TYPO3 also removes
**every** reference to that file, in every table, without asking who owns them.
If an editor had used the same file in a page, in a news record or in a text
link, an unconditional cleanup would take the image out of those records as
well, silently. The extension therefore checks both the file references and the
reference index before it deletes anything, and keeps the file whenever either
of them still names it.

Removing the image through :guilabel:`Remove` follows the same path.

Known limitations
=================

One image, no cropping, no variants
    A profile has exactly one image. It is stored and delivered as it was
    uploaded — there is no cropping step, no focus point, and no generated size
    variants. An installation that needs processed images can replace the single
    Fluid partial that renders it.

Image metadata cannot be edited from the frontend
    Alternative text, title and copyright live on the file record and on the
    file reference, and no endpoint writes them. What a visitor sees as the
    alternative text is either the text an editor entered in the backend, or a
    generated sentence naming the profile. Editing the metadata is a backend
    task.

Deleting the file does not necessarily free disk space
    Whether a deleted file is really removed depends on the storage it lives in.
    A storage with a recycler folder receives the file instead of deleting it,
    which is a useful safety net and means the space is still occupied. The file
    record is gone in both cases, and the image no longer appears anywhere.

A file that another record uses is never deleted
    The cleanup described above keeps the file whenever anything else refers to
    it. This is the safe direction, and it means an installation can accumulate
    unused files whose last reference was removed in a way the reference index
    did not record. They are unused, not broken, and a reference index update
    followed by the file list in the backend finds them.

The image is not part of a full save
    Every other field can be written together with the rest of the record. The
    image cannot: it is written by picking a file, and a save that carries the
    other fields neither changes nor clears it. This is why an interrupted
    upload never leaves a profile half saved.

Simultaneous edits overwrite each other
    Two sessions editing the same profile overwrite each other's images as they
    do every other field. The last upload wins, and neither session is told.
