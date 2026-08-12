..  include:: /Includes.rst.txt

..  _editors-profile-records:

===============
Profile records
===============

A profile is an ordinary record, created in the :guilabel:`List` module on the
page the :guilabel:`Storage page` setting names. A profile stored anywhere else
is found by none of the three plugins — not by the list, not by the detail
plugin, and not by the edit plugin, which then reports to its owner that there
is no profile for them.

The record type is called :guilabel:`Profile`. Its addresses and e-mail
addresses are records of their own, but they are created inside the profile
form and never on their own.

..  _editors-profile-records-form:

The form
========

The form has six tabs, and the fields are distributed over them as follows:

..  list-table::
    :header-rows: 1

    *   -   Tab
        -   Fields

    *   -   :guilabel:`General`
        -   :guilabel:`Short name`, :guilabel:`First name`,
            :guilabel:`Last name`, :guilabel:`Birthday`, :guilabel:`Image`

    *   -   :guilabel:`Contact`
        -   :guilabel:`Addresses`, :guilabel:`Email addresses`

    *   -   :guilabel:`Text`
        -   :guilabel:`Biography`

    *   -   :guilabel:`Language`
        -   The language and the translation parent

    *   -   :guilabel:`Access`
        -   :guilabel:`Owner (website user)`, :guilabel:`Hide`, and the
            publish dates

    *   -   :guilabel:`Extended`
        -   Nothing of this extension

:guilabel:`Short name` is the only **required** field of the form. It is also
what the record is titled by in the backend, together with the last and first
name, so a short name that identifies the person makes the record lists
readable.

:guilabel:`First name` and :guilabel:`Last name` are optional. The website
shows both as the profile name and falls back to the short name when neither is
filled in.

:guilabel:`Image` holds a single image file. It is the same image the owner can
replace and remove from the website, so a file put here can be changed from the
frontend and the other way round.

..  _editors-profile-records-children:

Addresses and e-mail addresses
==============================

Both collections live on the :guilabel:`Contact` tab, are created inline, and
hold up to 99 records each. Entries are shown collapsed and only one is open at
a time, and their order is the order the website renders them in — drag them to
change it.

..  list-table::
    :header-rows: 1

    *   -   Record
        -   Fields

    *   -   :guilabel:`Address`
        -   :guilabel:`Type` (:guilabel:`Home`, :guilabel:`Work`,
            :guilabel:`Others`), :guilabel:`Address line 1`,
            :guilabel:`Address line 2`

    *   -   :guilabel:`Email address`
        -   :guilabel:`Type` (:guilabel:`Private`, :guilabel:`Business`,
            :guilabel:`Others`), :guilabel:`Email address` — required

Each child record carries its own :guilabel:`Access` tab with :guilabel:`Hide`
and publish dates. A hidden address or e-mail address disappears from the list
and the detail plugin, and stays visible — marked as hidden — to its owner in
the edit plugin, who can publish it again from there.

..  note::

    The editing surface on the website is slightly stricter than this form:
    it refuses an address whose first line is empty, while the backend form
    accepts one. An address created in the backend without a first line can
    therefore be saved here and will be rejected the next time its owner edits
    that field.

..  _editors-profile-records-owner:

The owner
=========

:guilabel:`Owner (website user)` on the :guilabel:`Access` tab is the single
field that decides whether a profile can ever be edited from the website. It
selects one website user record, and its default is :guilabel:`No owner`.

*   With **no owner**, the profile is a read-only record as far as the frontend
    is concerned. It is listed and it has a detail page, but nobody can reach
    it in the edit plugin and no :guilabel:`Edit profile` link is rendered for
    anybody.
*   With an owner, that website user — and only that user — sees the profile in
    the edit plugin after logging in, and sees the :guilabel:`Edit profile`
    link on the list and the detail page.

..  warning::

    Assign a website user to **at most one** profile. The edit plugin shows one
    profile per login, and where a user owns several it is always the same
    one — the profile with the lowest record id. The others are then not
    editable from the website at all.

Two things this field is not:

*   It is not a permission that the website evaluates on the client. The link
    on the list and the detail page is hidden for everybody else, but what
    actually protects a profile is the check the server makes on every write.
*   It is not a login. The website user record has to be one that can log in on
    this site; assigning it here grants no access on its own.

A profile that is hidden itself disappears from the list and the detail plugin,
but its owner still reaches it in the edit plugin, where it is marked as hidden
and remains editable. Publishing it again is a backend operation: the website
offers no control for the hidden state of a profile, only for that of its
addresses and e-mail addresses.
