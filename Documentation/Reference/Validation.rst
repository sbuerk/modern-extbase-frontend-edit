..  include:: /Includes.rst.txt

..  _reference-validation:

================
Validation rules
================

Every value a website user submits is checked before it reaches a record. The
rules are per record type and per field, and the same rules apply whether a
whole record or a single field was submitted.

How a save is validated
=======================

..  list-table::
    :header-rows: 1

    -   -   Save
        -   Checked
    -   -   A whole record
        -   Every field listed below for that record type. A field the payload
            does not carry takes its default, which is the empty value, so a
            required field that is left out is reported as empty.
    -   -   A single field
        -   Only that field. The rules of the other fields are not evaluated at
            all, so an inline save of one field never reports a different one
            as missing.

The list of fields below is at the same time the list of names a save may
address. A name that is not in it is refused with :code:`400` rather than
ignored, so :sql:`uid`, :sql:`pid`, :sql:`hidden`, the image and the owning
website user have no path into a record through a save.

Profile
=======

..  list-table::
    :header-rows: 1

    -   -   Field
        -   Rule
        -   Message id
    -   -   :code:`shortname`
        -   Required.
        -   :code:`validation.profile.shortname.empty`
    -   -   :code:`shortname`
        -   Between 2 and 255 characters.
        -   :code:`validation.profile.shortname.length`
    -   -   :code:`firstname`
        -   Optional, at most 255 characters.
        -   :code:`validation.profile.firstname.tooLong`
    -   -   :code:`lastname`
        -   Optional, at most 255 characters.
        -   :code:`validation.profile.lastname.tooLong`
    -   -   :code:`birthday`
        -   Optional. When given, a date in the format :code:`YYYY-MM-DD`.
        -   :code:`validation.profile.birthday.invalid`
    -   -   :code:`bio`
        -   Optional, at most 5000 characters.
        -   :code:`validation.profile.bio.tooLong`

The 255 character bounds are the length of the :sql:`varchar(255)` columns the
values are stored in. The 5000 character bound of the biography is not a column
limit — the column is a text column — it bounds the request payload.

A birthday is a date and nothing else: a value carrying a time of day is
rejected rather than truncated, and a date that does not exist, such as
:code:`2026-02-30`, is rejected rather than rolled forward. An empty value
means "no birthday" and passes.

Postal address
==============

..  list-table::
    :header-rows: 1

    -   -   Field
        -   Rule
        -   Message id
    -   -   :code:`type`
        -   One of :code:`home`, :code:`work`, :code:`others`. An empty value
            is refused as well.
        -   :code:`validation.address.type.invalid`
    -   -   :code:`line1`
        -   Required.
        -   :code:`validation.address.line1.empty`
    -   -   :code:`line1`
        -   At most 255 characters.
        -   :code:`validation.address.line1.tooLong`
    -   -   :code:`line2`
        -   Optional, at most 255 characters.
        -   :code:`validation.address.line2.tooLong`

E-mail address
==============

..  list-table::
    :header-rows: 1

    -   -   Field
        -   Rule
        -   Message id
    -   -   :code:`type`
        -   One of :code:`private`, :code:`business`, :code:`others`. An empty
            value is refused as well.
        -   :code:`validation.email.type.invalid`
    -   -   :code:`email`
        -   Required.
        -   :code:`validation.email.email.empty`
    -   -   :code:`email`
        -   A valid e-mail address.
        -   :code:`validation.email.email.invalid`
    -   -   :code:`email`
        -   At most 255 characters.
        -   :code:`validation.email.email.tooLong`

Two further ids exist and are shown only by a rule that names no message of its
own. No rule shipped with this extension does, so they appear once a rule set
is changed:

..  list-table::
    :header-rows: 1

    -   -   Message id
        -   Belongs to
    -   -   :code:`validation.choice.invalid`
        -   The value-set check used by the two :code:`type` fields.
    -   -   :code:`validation.date.invalid`
        -   The date check used by :code:`birthday`.

The messages of a rejected image upload are listed in
:ref:`reference-image-upload`.

Overriding the messages
=======================

All ids above are :code:`trans-unit` ids in
:file:`EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf`.
A translation is added the usual way, as a language file next to the English
one. Changing the English wording, or the wording of an already translated
language, is done with a resource override in
:file:`config/system/settings.php` or :file:`additional.php`.

The configuration path for this **differs between the supported TYPO3
versions**. On TYPO3 v13:

..  code-block:: php
    :caption: config/system/additional.php, TYPO3 v13

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride']['EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf'][]
        = 'EXT:my_sitepackage/Resources/Private/Language/Overrides/modern_extbase_frontend_edit.xlf';

On TYPO3 v14 that path was renamed, and the old one is no longer read:

..  code-block:: php
    :caption: config/system/additional.php, TYPO3 v14

    $GLOBALS['TYPO3_CONF_VARS']['LANG']['resourceOverrides']['EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf'][]
        = 'EXT:my_sitepackage/Resources/Private/Language/Overrides/modern_extbase_frontend_edit.xlf';

Both accept a list, so several override files can be stacked, and both accept a
locale as an additional first key to override one language only.

..  note::

    The TypoScript :typoscript:`_LOCAL_LANG` mechanism does **not** reach these
    labels. Validation messages are resolved by the validators themselves,
    through the fully qualified label reference rather than through a plugin's
    language context, and only a resource override applies there.

Messages carrying a number take it from the rule rather than from the text,
through positional placeholders. :code:`%1$s` and :code:`%2$s` in the
:code:`shortname` length message are the lower and the upper bound; every other
length message carries a single :code:`%1$s`, which is the upper bound. Keep
the placeholders and their order when rewriting a message.
