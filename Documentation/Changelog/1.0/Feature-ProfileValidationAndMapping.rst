..  include:: /Includes.rst.txt

..  _feature-profile-validation-and-mapping:

=============================================
Feature: Profile validation and field mapping
=============================================

Description
===========

The extension now ships the layer that checks what a frontend edit form submits
and writes the accepted values onto the profile records: payload objects for the
three record types, the rules each of their fields has to satisfy, and the
mapping from an accepted payload onto the domain model.

It answers two shapes of a save, both against the same set of rules:

..  list-table::
    :header-rows: 1

    *   -   Save
        -   What is checked

    *   -   A complete form
        -   Every field of the submitted record. Fields that were not sent take
            their default.

    *   -   A single field, edited in place
        -   Only that field. Fields that were not sent cannot produce an error,
            because their rules are never evaluated at all — an inline save of
            one field never reports a different one as missing.

Only fields that carry rules can be written. The rule set of a record is at the
same time the list of field names a save may address: a name that is not in it
is rejected outright rather than being ignored, so a save can never quietly
write nothing. Record identity is never taken from the payload — a profile
carries no :sql:`uid` and no :sql:`pid` field that a request could set, and a
storage page is therefore not something a request can choose.

What is validated
=================

..  list-table::
    :header-rows: 1

    *   -   Record
        -   Fields and rules

    *   -   Profile
        -   Short name is required and between 2 and 255 characters. First and
            last name are optional, up to 255 characters. The birthday is
            optional and is given as :code:`YYYY-MM-DD`. The biography is
            optional, up to 5000 characters.

    *   -   Postal address
        -   The type is one of :code:`home`, :code:`work` or :code:`others`.
            The first address line is required, up to 255 characters; the second
            is optional, up to 255 characters.

    *   -   E-mail address
        -   The type is one of :code:`private`, :code:`business` or
            :code:`others`. The address is required, has to be a valid e-mail
            address and is at most 255 characters long.

The birthday is deliberately a **date without a time of day**, matching the
column it is stored in. A value carrying a time is rejected rather than
truncated, and a date that does not exist — :code:`2026-02-30`, for instance —
is rejected as well rather than being rolled forward to the following month.

Rejected fields are reported per field, each with the message, an error code and
the values the rule was configured with, so a form can mark exactly the inputs
that need attention.

Changing the validation messages
================================

Every message this extension produces is a label in
:file:`EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf`
with an id starting with :code:`validation.`. They are addressed as follows:

..  list-table::
    :header-rows: 1

    *   -   Label id
        -   Shown when

    *   -   :code:`validation.profile.shortname.empty`
        -   No short name was entered.

    *   -   :code:`validation.profile.shortname.length`
        -   The short name is too short or too long.

    *   -   :code:`validation.profile.firstname.tooLong`,
            :code:`validation.profile.lastname.tooLong`,
            :code:`validation.profile.bio.tooLong`
        -   The value exceeds the length limit of that field.

    *   -   :code:`validation.profile.birthday.invalid`
        -   The birthday is not a valid :code:`YYYY-MM-DD` date.

    *   -   :code:`validation.address.type.invalid`,
            :code:`validation.email.type.invalid`
        -   The submitted type is not one of the offered ones.

    *   -   :code:`validation.address.line1.empty`,
            :code:`validation.email.email.empty`
        -   A required field was left empty.

    *   -   :code:`validation.address.line1.tooLong`,
            :code:`validation.address.line2.tooLong`,
            :code:`validation.email.email.tooLong`
        -   The value exceeds the length limit of that field.

    *   -   :code:`validation.email.email.invalid`
        -   The e-mail address is not a valid address.

    *   -   :code:`validation.choice.invalid`,
            :code:`validation.date.invalid`
        -   Fallbacks, used only by a rule that names no message of its own.

Translations are added the usual way, as a language file next to the English
one. To change the English wording, or the wording of a language that is
already translated, override the file in :file:`config/system/settings.php` (or
:file:`additional.php`):

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride']['EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang.xlf'][]
        = 'EXT:my_sitepackage/Resources/Private/Language/Overrides/modern_extbase_frontend_edit.xlf';

..  note::

    The TypoScript :typoscript:`_LOCAL_LANG` mechanism does **not** reach these
    labels. Validation messages are resolved by the validators themselves,
    through the fully qualified label reference rather than through a plugin's
    language context, and only :php:`locallangXMLOverride` applies there.

Messages with a number in them take it from the rule rather than from the text,
using positional placeholders: :code:`%1$s` and :code:`%2$s` are the lower and
the upper bound of a length rule, so a limit that is changed in the rule cannot
drift away from the number the message shows. Keep the placeholders when
rewriting a message, and keep their order.

Known limitations
=================

Nothing is saved yet
    This release adds the checking and the mapping, not the writing. No record
    is created, changed or deleted, and there is no address a form could submit
    to. The editing endpoint and the persistence that goes with it are part of a
    later release. Until then, the profile records are edited in the backend, as
    before.

Child records are not removed
    A save that leaves out one of a profile's addresses or e-mail addresses
    describes the set that should remain, but nothing acts on that description
    yet — removing what is no longer in it belongs to the write path.

Publishing and images are not form fields
    Hiding or unhiding a profile is not part of a save, and neither is the
    profile image. Both are separate actions with their own handling, and both
    come with the editing endpoint.

Only the default language, and never in a workspace
    The restrictions stated for the domain model are unchanged: frontend
    editing will apply to records of the default language and will be refused
    while a workspace is active.
