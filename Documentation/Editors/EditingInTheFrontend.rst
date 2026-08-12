..  include:: /Includes.rst.txt

..  _editors-editing-in-the-frontend:

=======================
Editing in the frontend
=======================

The page holding :guilabel:`Profiles: edit` is what a website user works on.
It shows one profile — theirs — and, depending on who is looking and on the
state of the installation, either an editing surface or a plain rendering of
the same record.

..  _editors-editing-in-the-frontend-states:

The four states of the edit plugin
==================================

The plugin always renders the heading :guilabel:`Your profile`. What follows it
is one of four things.

Nobody is logged in
    A sentence asks the visitor to log in. Nothing else is rendered, and no
    profile data reaches the page.

    ..  figure:: /files/images/frontend-edit/anonymous.avif
        :alt: The heading "Your profile", followed by the sentence "You are not logged in. Log in to view and edit your profile."
        :class: with-border

        A visitor who is not logged in is told so in one sentence — no form, no
        error page.

    ..  note::

        The plugin renders no link to a login page. It has no setting naming
        one, and it does not guess.

Somebody is logged in, but owns no profile
    A different sentence says that there is no profile assigned to the account
    yet. The two cases are deliberately worded differently: "log in first" and
    "you have no profile yet" are different instructions, and one sentence
    covering both would be actionable for neither visitor.

A workspace is active
    The profile is rendered, readable and complete, under a sentence saying
    that it is shown as it appears in this workspace and that editing is only
    available in the live workspace. No editing controls are drawn at all —
    writing is refused in a workspace, and a surface that cannot save would be
    worse than none.

The owner is logged in, in the live workspace
    The editing surface, described in the rest of this page.

..  _editors-editing-in-the-frontend-fallback:

The rendered profile is the fallback
====================================

The website renders the whole record as ordinary HTML, and the editing surface
is layered on top of it in the browser. Where that layering does not happen —
JavaScript switched off, a script that did not load, a page type for the
editing requests that nothing answers — the rendered record is what stays on
the page.

..  figure:: /files/images/frontend-edit/server-rendered.avif
    :alt: The profile of Ada Lovelace as plain HTML: the name as a heading, a birthday and a biography, a list of four addresses of which the last is marked "Hidden", and a list of two e-mail addresses rendered as links.
    :class: with-border

    The same profile with the editing component absent: name, birthday,
    biography, addresses and e-mail addresses, and not a single control. The
    hidden address is in the list and marked as hidden, because this view is
    the owner's.

What is missing in that situation is the editing, never the content, and never
an error page. An editor can leave the plugin on the page in any of those
cases.

..  _editors-editing-in-the-frontend-surface:

The editing surface
===================

Once the component has loaded, it replaces the rendered record with its own
surface. Everything the surface can change is inside it, so the page can never
show a stale value next to a fresh one.

..  figure:: /files/images/frontend-edit/owner-view.avif
    :alt: The editing surface at rest: an "Edit all fields" button, the portrait with a file control and a disabled "Remove" button, the five profile fields each with their value and an "Edit" button, then the addresses and e-mail addresses, each record with its own buttons and each section ending in an empty form with an "Add" button.
    :class: with-border

    The surface at rest. Every field shows its stored value with an
    :guilabel:`Edit` button next to it; each record carries an :guilabel:`Edit
    all fields` button of its own; each child record adds :guilabel:`Move up`,
    :guilabel:`Move down`, :guilabel:`Hide` and :guilabel:`Remove`; and each
    collection ends in an empty form for a new entry. The hidden address is
    marked :guilabel:`Hidden` and offers :guilabel:`Show` instead of
    :guilabel:`Hide`.

Every save is answered with the profile as it is stored **afterwards**, and
that answer is what the page then shows. A value the server trims or normalises
therefore becomes visible immediately, and the surface cannot drift away from
what is in the database.

..  _editors-editing-in-the-frontend-field:

Editing one field
=================

:guilabel:`Edit` next to a value turns that value into a control and puts the
cursor in it. :guilabel:`Apply` sends **only that field**;
:guilabel:`Cancel` closes it again without sending anything.

..  figure:: /files/images/frontend-edit/field-open.avif
    :alt: The field "First name" switched into a text control holding "Ada", with an "Apply" and a "Cancel" button to its right.
    :class: with-border

    One field open for editing. Two buttons, and they act on this field alone.

:guilabel:`Cancel` puts the field back to the value the server last confirmed —
which, after a save in the same visit, is that saved value rather than the one
the page was opened with.

The keyboard works as it does in any single-line form:

..  list-table::
    :header-rows: 1

    *   -   Key
        -   Effect

    *   -   :kbd:`Enter`
        -   Applies the open field. Not bound in the biography, where it
            inserts a line break — taking that away would make a biography a
            single line.

    *   -   :kbd:`Escape`
        -   Cancels the open field, discarding what was typed.

..  _editors-editing-in-the-frontend-record:

Editing a whole record
======================

:guilabel:`Edit all fields` opens every field of **one** record at once and
replaces itself with :guilabel:`Save all fields` and :guilabel:`Cancel`. All
fields are then sent in a single request.

..  figure:: /files/images/frontend-edit/record-open.avif
    :alt: The profile record fully open: "Save all fields" and "Cancel" at the top, and the short name, first name, last name, birthday and biography all as controls, while the addresses and e-mail addresses below stay in their read view with their own Edit buttons.
    :class: with-border

    The profile record open as a whole. The child records below are untouched
    by it — each carries its own :guilabel:`Edit all fields`.

The two modes are labelled differently on purpose: they sit next to each other
on the same surface, and :guilabel:`Apply` on one field means something else
than :guilabel:`Save all fields`.

The birthday is edited in the browser's own date control, and the biography in
a multi-line control.

..  _editors-editing-in-the-frontend-children:

Addresses and e-mail addresses
==============================

Each collection is a list of records, each with its own buttons, followed by an
empty form for a new entry.

Every record is headed by its own content — its type and its first line, so
:guilabel:`Work · Difference Engine Road 1` — and the buttons that act on it sit
on that same line. The heading is deliberately **not** a number: the records can
be reordered, and a numbered heading would rename every entry below the one that
was just moved. A record keeps its heading wherever it ends up. A record with
neither a type nor a first line has no heading, and gains one as soon as
something is entered.

The buttons of a record are:

..  list-table::
    :header-rows: 1

    *   -   Button
        -   Effect

    *   -   :guilabel:`Add`
        -   Creates a record from what was typed into the empty form at the end
            of the collection. It is stored last, and the form starts over so
            that a second entry is not created from the first one's leftovers.

    *   -   :guilabel:`Remove`
        -   Deletes the record. There is no confirmation step and no undo on
            the website.

    *   -   :guilabel:`Move up`, :guilabel:`Move down`
        -   Change the order of the collection, which is the order the detail
            page renders it in. The button that would move the record out of
            the list is disabled.

    *   -   :guilabel:`Hide`, :guilabel:`Show`
        -   Take the record off the list and the detail page, or put it back. A
            hidden record stays in this surface and is marked
            :guilabel:`Hidden`, which is why the owner can find it again.

Every one of these takes effect immediately and is stored; none of them waits
for a save step.

..  _editors-editing-in-the-frontend-image:

The profile image
=================

The image is labelled :guilabel:`Portrait` and behaves unlike every other field
on the surface: choosing a file uploads it straight away, and
:guilabel:`Remove` deletes it straight away. There is no :guilabel:`Apply` and
nothing to cancel, because there is nothing to look at between picking a file
and having uploaded it.

A replaced or removed image is deleted from the file storage as well, not only
from the record — unless something else on the site still references that file,
in which case it is kept.

When an upload is refused, nothing was stored: the file control is emptied
again and a notice says that the image was not stored and has to be chosen
again. That is not a formality — the file really is gone as far as the server
is concerned, and a control still showing its name would state the opposite.

..  _editors-editing-in-the-frontend-refused:

When a change is refused
========================

A rejected save keeps what was typed and shows the reason at the field it
belongs to, so nothing has to be entered again. Nothing is stored.

..  figure:: /files/images/frontend-edit/field-rejected.avif
    :alt: The field "Short name" open and empty, its control outlined in red, "Apply" and "Cancel" still beside it, and the message "Enter a short name." below it.
    :class: with-border

    A refused value. The field stays open with the typed value in it, and the
    reason is shown where the value is.

Failures that are not about the value itself are reported as one sentence for
the whole record:

..  list-table::
    :header-rows: 1

    *   -   Situation
        -   What the surface says

    *   -   The login has expired
        -   That the session has expired, and that the page should be reloaded
            and the login repeated.

    *   -   A workspace became active
        -   That records cannot be edited while a workspace is active.

    *   -   Anything else
        -   That the change could not be saved and should be tried again.

..  warning::

    Two people — or two browser tabs — editing the same profile overwrite each
    other's changes, and neither is told. The last save wins. Where that
    matters, treat a profile as edited by one person at a time.
