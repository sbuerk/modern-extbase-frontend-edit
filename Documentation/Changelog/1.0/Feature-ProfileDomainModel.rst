..  include:: /Includes.rst.txt

..  _feature-profile-domain-model:

=============================
Feature: Profile domain model
=============================

Description
===========

The extension now ships the profile domain model the frontend editing is built
on: a profile record with two manually sorted child collections, the matching
backend editing forms, and repositories for reading them.

Three tables are added:

..  list-table::
    :header-rows: 1

    *   -   Table
        -   Contents

    *   -   :sql:`tx_modernextbasefrontendedit_domain_model_profile`
        -   Short name, first name, last name, an optional image, an optional
            birthday, a biography text, the owning website user, and the
            relations to the two child tables.

    *   -   :sql:`tx_modernextbasefrontendedit_domain_model_address`
        -   Postal addresses of one profile: a type (:code:`home`,
            :code:`work` or :code:`others`) and two address lines.

    *   -   :sql:`tx_modernextbasefrontendedit_domain_model_email`
        -   Email addresses of one profile: a type (:code:`home`,
            :code:`work` or :code:`others`) and the address itself.

Addresses and email addresses belong to exactly one profile and are edited
inline in it. Both are sorted manually, and the order an editor arranges them
in is the order they are read back in.

All three tables are language aware, workspace aware and support the usual
publishing controls — hide, start and stop time, and access group
restrictions. Deleting a record marks it deleted rather than removing the row.

Record ownership
================

A profile carries an :sql:`fe_user` field naming the website user who owns it.
It is optional: a profile without an owner is an ordinary editorial record, and
a profile with one is a record that the owning website user will be able to
edit from the frontend.

How ownership is stored is not fixed in the parts that use it. Everything above
the storage layer asks
:php:`\SBUERK\ModernExtbaseFrontendEdit\Security\ProfileOwnershipResolverInterface`
which profiles a given website user owns, so an installation that keeps that
information elsewhere — in a relation table, for instance, or in a group
membership — replaces that one service and nothing else.

Reading profiles: visible records and editable records
======================================================

Each of the three tables has two repositories, and which one is used decides
what is visible:

*   The repositories in :php:`\SBUERK\ModernExtbaseFrontendEdit\Domain\Repository`
    return what any website visitor may see. Hidden records, records outside
    their start and stop time and records restricted to a website user group
    the visitor is not in are filtered out, and the configured
    :typoscript:`persistence.storagePid` applies. These are the repositories for
    displaying profiles.

*   The repositories in :php:`\SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit`
    additionally return records an editor has hidden, so that an owner can see
    a hidden entry of their own and unhide it again. Start and stop times and
    access group restrictions still apply.

The split is two sets of classes rather than one repository with a switch,
because a switch is shared state: whoever flips it last decides what every
later caller in the same request gets to see. Only the methods that say so
return hidden records; the inherited finders such as :php:`findAll()` and
:php:`findByUid()` stay restricted to visible records in both sets.

Known limitations
=================

Two restrictions apply to editing these records from the frontend, and both are
inherent to how Extbase writes records rather than to this extension. They are
stated here because they decide whether the feature fits an installation.

Workspaces
    Frontend editing is refused while a workspace is active. Extbase
    persistence does not create workspace versions — it writes to the live
    record, whatever workspace the request runs in — so a change made in a
    workspace would silently become a live change that no one can review or
    roll back. Refusing the write is the only correct behaviour available.
    Editing in the backend is unaffected and workspace aware as usual.

Translations
    Frontend editing works on the default language only. Records created
    through Extbase persistence always end up in the default language and
    cannot be linked to an original record as its translation, so there is no
    way to create or edit a translation from the frontend. Translations are
    created in the backend, as usual. On sites configured with
    :yaml:`fallbackType: strict`, an untranslated profile is not visible in the
    translated language until it has been translated there.

The frontend plugins that use this domain model follow in a later release. What
this change adds is the data model, the backend editing forms and the read
side.
