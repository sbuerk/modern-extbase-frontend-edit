..  include:: /Includes.rst.txt

..  _reference-endpoints:

==============
JSON endpoints
==============

The contract of the editing endpoints, for a client other than the one this
extension ships. Everything below is enforced by the server; a client that
violates it receives one of the status codes in the last section.

How they are addressed
======================

The endpoints are a :typoscript:`PAGE` object of their own, keyed on a page
type. They answer on whichever page the edit plugin sits on, so no page has to
be created for them.

..  list-table::
    :header-rows: 1

    -   -   Setting
        -   TypoScript constant
        -   Default
    -   -   :yaml:`modernextbasefrontendedit.ajaxPageType`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.settings.ajaxPageType`
        -   :code:`1589`

The page object renders the Extbase plugin :code:`Ajax` of the extension
directly, with :typoscript:`config.disableAllHeaderCode = 1` so the body is
exactly what the action produced, and :typoscript:`config.no_cache = 1`.
:typoscript:`view.formatToPageTypeMapping.json` is set to the same number.

..  important::

    **The URLs cannot be assembled by a client.** The action name travels in
    the query string, as
    :code:`tx_modernextbasefrontendedit_ajax[action]=saveField`, and is
    therefore part of the cHash. A missing or wrong cHash is answered with
    :code:`404` by TYPO3 before the plugin runs, and a cHash cannot be computed
    in a browser.

    The server therefore builds one finished URL per endpoint and hands the map
    to the client. The plugin shipped with this extension renders it as JSON
    into the :code:`data-endpoints` attribute of its markup, next to the
    request token in :code:`data-token`. A second client obtains its URLs the
    same way — from the server, never by concatenation.

The map carries eight entries: :code:`save`, :code:`saveField`,
:code:`addChild`, :code:`removeChild`, :code:`reorderChildren`,
:code:`setChildVisibility`, :code:`uploadImage` and :code:`removeImage`.
:code:`read` is deliberately not among them. When the page type is :code:`0`
the map is empty.

The endpoints
=============

All of them are :code:`POST`. All of them except :code:`uploadImage` take a
JSON object as the request body.

..  list-table::
    :header-rows: 1

    -   -   Endpoint
        -   Body keys
        -   Writes
    -   -   :code:`read`
        -   :code:`uid` (optional)
        -   Nothing. Returns the caller's profile.
    -   -   :code:`save`
        -   :code:`uid`, :code:`data`, and :code:`child` with
            :code:`childUid` when a child record is addressed
        -   Every writable field of one record at once.
    -   -   :code:`saveField`
        -   :code:`uid`, :code:`field`, :code:`value`, and :code:`child` with
            :code:`childUid` when a child record is addressed
        -   One named field of one record.
    -   -   :code:`addChild`
        -   :code:`uid`, :code:`child`, :code:`data`
        -   One new child record, appended last.
    -   -   :code:`removeChild`
        -   :code:`uid`, :code:`child`, :code:`childUid`
        -   Deletes the addressed child record.
    -   -   :code:`reorderChildren`
        -   :code:`uid`, :code:`child`, :code:`order`
        -   The sorting of one collection.
    -   -   :code:`setChildVisibility`
        -   :code:`uid`, :code:`child`, :code:`childUid`, :code:`hidden`
        -   The hidden state of one child record.
    -   -   :code:`uploadImage`
        -   A :code:`multipart/form-data` body — see
            :ref:`reference-image-upload`
        -   The profile image.
    -   -   :code:`removeImage`
        -   :code:`uid`
        -   Clears the profile image.

The value types are checked strictly, and a value of the wrong type is a
:code:`400` rather than something that is cast:

..  list-table::
    :header-rows: 1

    -   -   Key
        -   Type
    -   -   :code:`uid`, :code:`childUid`
        -   A JSON integer greater than :code:`0`. A numeric string is refused.
    -   -   :code:`child`
        -   :code:`"address"` or :code:`"email"`. Absent or :code:`null`
            addresses the profile itself, which :code:`save` and
            :code:`saveField` allow and the four collection endpoints do not.
    -   -   :code:`data`
        -   A JSON object.
    -   -   :code:`field`
        -   A non-empty string naming a writable field — see
            :ref:`reference-validation`.
    -   -   :code:`value`
        -   A string, or :code:`null`.
    -   -   :code:`hidden`
        -   A JSON boolean.
    -   -   :code:`order`
        -   A JSON array of integers greater than :code:`0`. It has to be a
            permutation of the **whole** collection: a wrong length or a
            repeated uid is refused before anything is written.

:code:`uid` is required by every endpoint except :code:`read`, and it is only
ever a filter: the set of records a request may reach is resolved from the
session, and a uid outside that set is answered like a uid that does not exist.

The request token
=================

Every writing endpoint requires a TYPO3 request token in the
:code:`X-TYPO3-RequestToken` header. It is a hash signed JWT bound to a nonce
cookie of that browser, with the scope

..  code-block:: text

    modern_extbase_frontend_edit/record-save

A token that is missing, that cannot be verified, or that carries a different
scope is refused identically. The token is proof that the browser loaded a page
of this site; it is not authorisation, and it does not replace the login check.

The :code:`__RequestToken` body parameter TYPO3 also accepts is not usable
here: it is read from the parsed request body, which is empty for a request
carrying a JSON body.

:code:`read` requires no token and no login. It changes nothing, and answering
an anonymous caller differently from a logged-in non-owner would say which
profiles exist.

The guards, in order
====================

For a writing endpoint with a JSON body, each check runs before any value of
the request body is looked at:

..  list-table::
    :header-rows: 1

    -   -   Order
        -   Check
        -   Failure
    -   -   1
        -   The request method is :code:`POST`.
        -   :code:`405`, with an :code:`Allow: POST` header.
    -   -   2
        -   The media type is :code:`application/json`.
        -   :code:`400`
    -   -   3
        -   A valid request token of the scope above was received.
        -   :code:`403`
    -   -   4
        -   A website user is logged in.
        -   :code:`403`
    -   -   5
        -   The request runs in the live workspace.
        -   :code:`409`
    -   -   6
        -   The body is empty, or a JSON object.
        -   :code:`400`
    -   -   7
        -   The addressed record is in the set the session owns.
        -   :code:`404`
    -   -   8
        -   The remaining body keys have the required types.
        -   :code:`400`
    -   -   9
        -   The submitted values satisfy the validation rules.
        -   :code:`422`

:code:`read` runs steps 1, 2, 6 and 7 only. :code:`uploadImage` replaces step 2
with :code:`multipart/form-data` and adds a check that exactly one file was
sent; its order is otherwise the same.

The response envelope
=====================

Every response carries a JSON body and the content type
:code:`application/json; charset=utf-8` — successes and failures alike.

A success is :code:`200` and one key:

..  code-block:: json

    {
        "data": {}
    }

:code:`data` is the whole profile as it stands **after** the write, not an echo
of the request, and it is the same document for every endpoint — including the
ones that changed a single field.

A failure carries one key as well:

..  code-block:: json

    {
        "errors": [
            {
                "code": 1786495903,
                "message": "Request token missing or invalid."
            }
        ]
    }

:code:`code` is a TYPO3 style exception code identifying the one line that
refused the request. :code:`message` is written for a developer, is not
localized, and never repeats a value the request carried.

A :code:`422` uses the same key with one entry per rejected value, and those
entries carry a :code:`field`:

..  code-block:: json

    {
        "errors": [
            {
                "field": "shortname",
                "code": 1221560718,
                "message": "Enter a short name."
            }
        ]
    }

:code:`field` is the name of the rejected field, or :code:`null` for an error
that belongs to the record rather than to one of its fields. A rejected image
upload is keyed under :code:`image`. The :code:`message` of a validation error
**is** localized: it comes from the label file and is already translated and
substituted.

The profile document
====================

The object under :code:`data`, and the same document the edit plugin renders
into its markup:

..  list-table::
    :header-rows: 1

    -   -   Key
        -   Value
    -   -   :code:`uid`
        -   The profile uid.
    -   -   :code:`shortname`, :code:`firstname`, :code:`lastname`,
            :code:`bio`
        -   Strings.
    -   -   :code:`birthday`
        -   :code:`YYYY-MM-DD`, or :code:`""` for "no birthday".
    -   -   :code:`hidden`
        -   Boolean. Readable, and writable by no endpoint.
    -   -   :code:`image`
        -   :code:`null`, or an object with :code:`uid` (the
            :sql:`sys_file_reference` uid), :code:`fileUid` (the
            :sql:`sys_file` uid), :code:`publicUrl`, :code:`name`,
            :code:`extension`, :code:`mimeType`, :code:`size`, :code:`title`,
            :code:`alternative`, :code:`width` and :code:`height`.
    -   -   :code:`addresses`
        -   A list of objects with :code:`uid`, :code:`type`, :code:`line1`,
            :code:`line2` and :code:`hidden`, in their stored order.
    -   -   :code:`emails`
        -   A list of objects with :code:`uid`, :code:`type`, :code:`email` and
            :code:`hidden`, in their stored order.

Both collections contain the records the owner has hidden, marked by their
:code:`hidden` flag. That is what lets an owner find and publish them again.

Status codes
============

..  list-table::
    :header-rows: 1

    -   -   Status
        -   Meaning
    -   -   :code:`200`
        -   The write was performed. The body carries the resulting document.
    -   -   :code:`400`
        -   The request is malformed: a wrong media type, a body that is not a
            JSON object, a missing or wrongly typed key, an unknown child
            collection, an unknown field name, or more than one uploaded file.
    -   -   :code:`403`
        -   The request token is missing or invalid, or no website user is
            logged in. Which of the two is not distinguished.
    -   -   :code:`404`
        -   The addressed record does not exist, or does not belong to the
            calling session. The two are deliberately indistinguishable.
    -   -   :code:`405`
        -   The request method is not :code:`POST`. The response carries
            :code:`Allow: POST`.
    -   -   :code:`409`
        -   A workspace is active. The request is well formed and authorised,
            and the state of the session is what makes it unanswerable.
    -   -   :code:`422`
        -   A submitted value was rejected by a validation rule. The body names
            the field.
