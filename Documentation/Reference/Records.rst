..  include:: /Includes.rst.txt

..  _reference-records:

===================
Records and columns
===================

The extension adds three tables. One profile record, and two child tables whose
records belong to exactly one profile and are edited inline in it.

The three tables
================

..  list-table::
    :header-rows: 1

    -   -   Table
        -   Holds
    -   -   :sql:`tx_modernextbasefrontendedit_domain_model_profile`
        -   One profile: names, an optional birthday, a biography, an optional
            image, the owning website user, and the relations to the two child
            tables.
    -   -   :sql:`tx_modernextbasefrontendedit_domain_model_address`
        -   The postal addresses of one profile. Manually sorted, not shown
            as a table of its own in the backend.
    -   -   :sql:`tx_modernextbasefrontendedit_domain_model_email`
        -   The e-mail addresses of one profile. Manually sorted, not shown
            as a table of its own in the backend.

Control columns
===============

All three tables are configured the same way for the columns TYPO3 manages
itself:

..  list-table::
    :header-rows: 1

    -   -   Capability
        -   Columns
    -   -   Timestamps
        -   :sql:`tstamp`, :sql:`crdate`
    -   -   Soft delete
        -   :sql:`deleted` — deleting a record marks the row rather than
            removing it.
    -   -   Publishing controls
        -   :sql:`hidden`, :sql:`starttime`, :sql:`endtime`
    -   -   Language
        -   :sql:`sys_language_uid`, :sql:`l10n_parent`, :sql:`l10n_source`,
            :sql:`l10n_diffsource`
    -   -   Workspaces
        -   :sql:`versioningWS` is enabled, so the :sql:`t3ver_*` columns exist
            and backend editing is workspace aware.

..  note::

    There is **no** :sql:`fe_group` column on any of the three tables. Access
    group restrictions are therefore not part of the visibility of a profile,
    an address or an e-mail address — only hiding, start time and end time are.

The two child tables additionally carry :sql:`sorting` as their manual sort
column and are marked :php:`hideTable`, so they are reached through the profile
record and not through a list module view of their own.

Profile
=======

..  list-table::
    :header-rows: 1

    -   -   Column
        -   Type
        -   Notes
    -   -   :sql:`shortname`
        -   :php:`input`, trimmed, required
        -   The record label. :sql:`lastname` and :sql:`firstname` are appended
            to it in the backend record title.
    -   -   :sql:`firstname`
        -   :php:`input`, trimmed
        -   Optional.
    -   -   :sql:`lastname`
        -   :php:`input`, trimmed
        -   Optional. Records are listed sorted by last name, then first name.
    -   -   :sql:`birthday`
        -   :php:`datetime` with :php:`format => date` and
            :php:`dbType => date`, nullable, default :php:`null`
        -   A date without a time of day. The column cannot store one.
    -   -   :sql:`bio`
        -   :php:`text`
        -   Optional free text.
    -   -   :sql:`image`
        -   :php:`file`, :php:`relationship => manyToOne`,
            :php:`allowed => common-image-types`
        -   At most one image per profile, stored as a
            :sql:`sys_file_reference`. What the frontend upload accepts is
            narrower than the TCA — see :ref:`reference-image-upload`.
    -   -   :sql:`addresses`
        -   :php:`inline` to
            :sql:`tx_modernextbasefrontendedit_domain_model_address`
        -   :php:`foreign_field => profile`,
            :php:`foreign_table_field => tablenames`,
            :php:`foreign_sortby => sorting`, at most 99 records, language
            synchronization allowed.
    -   -   :sql:`emails`
        -   :php:`inline` to
            :sql:`tx_modernextbasefrontendedit_domain_model_email`
        -   The same configuration, for the e-mail addresses.
    -   -   :sql:`fe_user`
        -   :php:`select`, :php:`renderType => selectSingle`, on
            :sql:`fe_users`, :php:`maxitems => 1`, default :php:`0`
        -   The website user owning the record. A single value select is stored
            as a plain integer column. :php:`0` is the item labelled as "no
            owner" and means the record belongs to nobody.

Ownership is read through
:php:`\SBUERK\ModernExtbaseFrontendEdit\Security\ProfileOwnershipResolverInterface`,
so an installation that keeps it somewhere other than this column replaces that
one service and leaves the rest untouched.

Postal address
==============

..  list-table::
    :header-rows: 1

    -   -   Column
        -   Type
        -   Notes
    -   -   :sql:`profile`
        -   :php:`passthrough`
        -   The uid of the owning profile, written by the inline relation. Not
            an editable field, and not a property of the domain model.
    -   -   :sql:`tablenames`
        -   :php:`passthrough`
        -   The :php:`foreign_table_field` of the relation.
    -   -   :sql:`type`
        -   :php:`select`, :php:`renderType => selectSingle`,
            :php:`dbFieldLength => 150`, default :php:`others`
        -   One of :code:`home`, :code:`work`, :code:`others`.
    -   -   :sql:`line1`
        -   :php:`input`, trimmed
        -   The record label.
    -   -   :sql:`line2`
        -   :php:`input`, trimmed
        -   Optional.

The three accepted values of :sql:`type`, with the label ids they are rendered
through:

..  list-table::
    :header-rows: 1

    -   -   Value
        -   Backend and read plugins
        -   Editing surface
    -   -   :code:`home`
        -   :code:`tx_modernextbasefrontendedit_domain_model_address.type.home`
        -   :code:`choice.address.type.home`
    -   -   :code:`work`
        -   :code:`tx_modernextbasefrontendedit_domain_model_address.type.work`
        -   :code:`choice.address.type.work`
    -   -   :code:`others`
        -   :code:`tx_modernextbasefrontendedit_domain_model_address.type.others`
        -   :code:`choice.address.type.others`

The first column of ids lives in
:file:`EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf`,
the second in :file:`locallang.xlf` next to it. Both describe the same stored
value, so overriding one without the other makes the read view and the editing
surface disagree.

E-mail address
==============

..  list-table::
    :header-rows: 1

    -   -   Column
        -   Type
        -   Notes
    -   -   :sql:`profile`
        -   :php:`passthrough`
        -   The uid of the owning profile.
    -   -   :sql:`tablenames`
        -   :php:`passthrough`
        -   The :php:`foreign_table_field` of the relation.
    -   -   :sql:`type`
        -   :php:`select`, :php:`renderType => selectSingle`,
            :php:`dbFieldLength => 150`, default :php:`others`
        -   One of :code:`private`, :code:`business`, :code:`others`.
    -   -   :sql:`email`
        -   :php:`email`, required
        -   The address itself, and the record label.

..  important::

    The accepted values of :sql:`type` are **not** the same set as on the
    address table. An address is :code:`home`, :code:`work` or :code:`others`;
    an e-mail address is :code:`private`, :code:`business` or :code:`others`.

..  list-table::
    :header-rows: 1

    -   -   Value
        -   Backend and read plugins
        -   Editing surface
    -   -   :code:`private`
        -   :code:`tx_modernextbasefrontendedit_domain_model_email.type.private`
        -   :code:`choice.email.type.private`
    -   -   :code:`business`
        -   :code:`tx_modernextbasefrontendedit_domain_model_email.type.business`
        -   :code:`choice.email.type.business`
    -   -   :code:`others`
        -   :code:`tx_modernextbasefrontendedit_domain_model_email.type.others`
        -   :code:`choice.email.type.others`

Schema definition
=================

The database schema is generated from the TCA. :file:`ext_tables.sql` of this
extension defines exactly two columns, the :sql:`type` column of each child
table:

..  code-block:: sql

    type varchar(150) DEFAULT 'others' NOT NULL

They are pinned because the definition TYPO3 generates for a
:php:`type => select` column differs between v13 and v14 — v13 uses an empty
default, v14 the :php:`default` from the TCA. Everything else, including every
business column above, the inline parent pointers and the control columns, is
created from the TCA and must not be repeated in :file:`ext_tables.sql`.
