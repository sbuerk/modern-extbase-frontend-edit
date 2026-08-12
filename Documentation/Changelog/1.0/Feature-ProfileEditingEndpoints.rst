..  include:: /Includes.rst.txt

..  _feature-profile-editing-endpoints:

==================================
Feature: Profile editing endpoints
==================================

Description
===========

The extension now writes. Seven JSON endpoints let a logged-in website user
change their own profile from the frontend — the profile fields, its postal
addresses and its e-mail addresses, including their order and whether a single
one of them is shown.

All seven are :code:`POST` requests with a JSON request body, answered with a
JSON response body on every path, success and failure alike. They are addressed
through one page type, so no separate page has to be created for them: the
endpoints answer on whichever page the edit plugin sits on.

..  list-table::
    :header-rows: 1

    *   -   Endpoint
        -   What it does

    *   -   :code:`read`
        -   Returns the caller's profile with both of its collections.

    *   -   :code:`save`
        -   Writes every editable field of one record at once — the profile
            itself, or one address or e-mail address.

    *   -   :code:`saveField`
        -   Writes one named field of one record, for an editor that saves
            while typing.

    *   -   :code:`addChild`
        -   Adds one address or e-mail address. It is appended, so it sorts
            last.

    *   -   :code:`removeChild`
        -   Removes one address or e-mail address **and deletes the record**.

    *   -   :code:`reorderChildren`
        -   Puts one of the two collections into the submitted order.

    *   -   :code:`setChildVisibility`
        -   Shows or hides a single address or e-mail address.

Every one of them answers with the whole profile as it stands **after** the
write, not with an echo of what was sent. A client that updated its own display
optimistically therefore cannot drift away from what was stored, and a client
that moved or removed a record gets the resulting order back with it.

The order an address or e-mail address is dragged into is the order it is read
back in, in the frontend and in the backend list alike — the frontend writes the
same dense numbering the backend does, so a record arranged in one can be
rearranged in the other without a repair step.

Configuration
=============

The endpoints need one setting, and it already exists: the page type they answer
on. It is available as a site setting of the site set :guilabel:`Profiles`
shipped by this extension, and as a TypoScript constant for installations that
configure their sites with :sql:`sys_template` records.

..  list-table::
    :header-rows: 1

    *   -   Setting
        -   TypoScript constant
        -   Meaning

    *   -   :yaml:`modernextbasefrontendedit.ajaxPageType`
        -   :typoscript:`plugin.tx_modernextbasefrontendedit.settings.ajaxPageType`
        -   The page type (:code:`&type=`) the endpoints answer on. Defaults to
            :code:`1589`.

Change it only if the number collides with another extension on the site. The
same value is used for :typoscript:`view.formatToPageTypeMapping.json`, so both
spellings of an endpoint address resolve to the same page type and a link can
never point at a type nobody renders.

..  important::

    The page object this extension registers sets
    :typoscript:`config.no_cache = 1`, and that is **required**, not a default
    worth trimming. Without it TYPO3 writes a page cache entry for the endpoint
    — an entry that is shared by every website user in the same user group,
    because the page cache distinguishes visitors by their groups and not by
    their user record. Overriding the setting on this page type re-opens that.

Nothing else has to be configured. The endpoints are not a content element, they
are not offered in the content element wizard, and they cannot be placed on a
page by an editor.

Security model
==============

A write is accepted only when all of the following hold, checked in this order
and before any value of the request body is looked at: the request is a
:code:`POST` carrying :code:`application/json`; it carries a valid TYPO3 request
token in the :code:`X-TYPO3-RequestToken` header, which proves the browser
loaded a page of this site rather than being driven from a foreign one; a
website user is logged in; and no workspace is active. The record is then
resolved **from the session** — the ownership of the caller's login decides which
profile is edited, and a record identifier in the request can only narrow that
set, never widen it or seed a lookup. Child records are addressed by their own
identifier *together with* the resolved profile, so an identifier belonging to
somebody else matches nothing. A record that is not the caller's and a record
that does not exist produce the identical answer, deliberately, so that the
endpoints cannot be used to find out which profiles exist. Only fields that
carry validation rules can be written at all: :sql:`uid`, :sql:`pid` and the
owning website user are not among them and have no path into a record, so
neither the storage page nor the ownership of a record can be changed by a
request.

Reading is deliberately looser in one respect and no looser in any other:
:code:`read` requires no request token and makes no login check. A read changes
nothing, and refusing an anonymous caller would make the endpoint answer
differently for "not logged in" than for "logged in, but not the owner" — which
is exactly the difference an attacker would use to enumerate profiles. Both
cases receive the same "not found" answer, because a caller without a login owns
nothing.

Known limitations
=================

A failed write is not rolled back
    A save is not a database transaction. If it fails part way through — a
    connection loss, a constraint violation — what was already written stays
    written, and the profile can be left in a half-updated state. Each endpoint
    writes in a single step to keep that window as small as possible, but the
    window exists. A save that reports an error should be repeated rather than
    assumed to have changed nothing.

Existing gaps in the sort order are not repaired
    Reordering a collection renumbers it without gaps. A collection whose order
    is *not* changed is left exactly as it is, so gaps that a record already had
    — from an earlier backend sorting operation, or from a record deleted
    elsewhere — stay. This is invisible in the frontend and in the backend,
    because both read records in their stored order rather than by their sorting
    number, but it means opening and saving a profile does not tidy up the
    numbering.

A profile cannot be hidden or published from the frontend
    Whether a profile is hidden is part of every response, so an editing
    interface can show the state, but no endpoint changes it.
    :code:`setChildVisibility` addresses an address or an e-mail address, as its
    name says. Hiding and publishing a whole profile is a different decision
    from hiding one of its e-mail addresses — it needs a rule about who may make
    a record public — and that rule is not part of this release. Use the backend.

The profile image cannot be uploaded or replaced
    The image is a backend-only field for now. Uploading is a different
    transport — a file upload, not a JSON document — with its own failure cases
    and its own rule for cleaning up the file behind a replaced image, and it is
    a release of its own rather than an eighth endpoint bolted onto a JSON API.

No rate limiting
    The endpoints are not rate limited. TYPO3 v14 offers an Extbase level
    mechanism for it and TYPO3 v13 does not, and using it on one version only
    would leave the two supported versions with a different security posture.
    Where this matters, limit the requests in front of TYPO3, in the web server
    or in a reverse proxy.

Simultaneous edits overwrite each other
    Two sessions editing the same profile overwrite each other's changes, the
    last write wins, and neither is told. There is no record version in the
    responses and no conflict answer.

Only the default language, and never in a workspace
    Unchanged from the earlier releases and now enforced by the endpoints: a
    write issued while a workspace is active is refused rather than applied,
    because it would silently change the published record instead of creating a
    workspace version. Editing applies to records of the default language.

This entry describes the endpoints, not an interface
    These are the addresses an editing interface talks to; on their own they
    change nothing a visitor sees. The plugin that uses them is described in
    :ref:`feature-profile-edit-plugin`, and ships in the same release. The
    endpoints are documented separately because they are a supported surface in
    their own right: a different interface may be built against them, and the
    security rules above apply to it just the same.
