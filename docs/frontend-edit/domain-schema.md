# Domain and schema

The frontend edit feature persists three tables: a `profile` with two manually
sorted 1:n children, `address` and `email`. All three are language, workspace,
soft-delete and hidden aware, because the editing rules downstream — the
workspace guard, the "hidden children stay editable" requirement, the
translation gap — only make sense against a schema that carries those
capabilities.

This page records the schema decisions and the reasons for them. **The
implementation follows in a later change**; nothing described here exists in
`Configuration/TCA/` yet. What is below is copy-pasteable and was derived by
reading `DefaultTcaSchema`, `TcaMigration`, `RelationHandler` and the shipped
changelogs of both core versions, not from memory.

## The three tables

Table names are prefixed `tx_modernextbasefrontendedit_domain_model_` and are
rewritten on repository initialization. The *Column* column is what the schema
analyzer generates — MySQL/MariaDB flavour — with no `ext_tables.sql` entry,
unless the note says otherwise.

| Table     | Field       | TCA `type`                           | Column                                   | Note                                                       |
|-----------|-------------|--------------------------------------|------------------------------------------|------------------------------------------------------------|
| `profile` | `shortname` | `input`                              | `varchar(255) DEFAULT '' NOT NULL`       | record label, `required`                                   |
| `profile` | `firstname` | `input`                              | `varchar(255) DEFAULT '' NOT NULL`       | `label_alt`                                                |
| `profile` | `lastname`  | `input`                              | `varchar(255) DEFAULT '' NOT NULL`       | `label_alt`                                                |
| `profile` | `image`     | `file`, `relationship: manyToOne`    | `int(11) unsigned DEFAULT 0 NOT NULL`    | reference *count*, not a uid; single nullable reference    |
| `profile` | `birthday`  | `datetime`, `dbType: date`           | `date DEFAULT NULL`                      | nullable on purpose, see below                             |
| `profile` | `bio`       | `text`                               | `longtext` (nullable)                    | `''` invariant lives in the model, see below               |
| `profile` | `addresses` | `inline`                             | `int(11) unsigned DEFAULT 0 NOT NULL`    | 1:n, manually sorted                                       |
| `profile` | `emails`    | `inline`                             | `int(11) unsigned DEFAULT 0 NOT NULL`    | 1:n, manually sorted                                       |
| `profile` | `fe_user`   | `select`, `renderType: selectSingle` | `int(11) unsigned DEFAULT 0 NOT NULL`    | owning frontend user, see below                            |
| `address` | `type`      | `select`, `dbFieldLength: 150`       | `varchar(150) DEFAULT 'others' NOT NULL` | **pinned in `ext_tables.sql`**, see below                  |
| `address` | `line1`     | `input`                              | `varchar(255) DEFAULT '' NOT NULL`       | record label                                               |
| `address` | `line2`     | `input`                              | `varchar(255) DEFAULT '' NOT NULL`       |                                                            |
| `email`   | `type`      | `select`, `dbFieldLength: 150`       | `varchar(150) DEFAULT 'others' NOT NULL` | **pinned in `ext_tables.sql`**, see below                  |
| `email`   | `email`     | `email`                              | `varchar(255) DEFAULT '' NOT NULL`       | `required`; `type=email` already produces the wanted shape |

The four capabilities come from `ctrl` alone, and every system column and index
follows from them. None of these columns is ever written by hand:

| Capability      | `ctrl` keys                                                                               | Columns it creates                                                                |
|-----------------|-------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------|
| language        | `languageField`, `transOrigPointerField`, `transOrigDiffSourceField`, `translationSource` | `sys_language_uid`, `l10n_parent`, `l10n_diffsource`, `l10n_source`, `l10n_state` |
| workspace       | `versioningWS`                                                                            | `t3ver_oid`, `t3ver_wsid`, `t3ver_state`, `t3ver_stage`                           |
| soft delete     | `delete`                                                                                  | `deleted`                                                                         |
| hidden / access | `enablecolumns.disabled`, `.starttime`, `.endtime`                                        | `hidden`, `starttime`, `endtime`                                                  |
| manual sorting  | `sortby` (children only)                                                                  | `sorting`                                                                         |

### `fe_user`: ownership, and why it is a scalar

The owning frontend user is a `select` with `renderType => 'selectSingle'`,
`foreign_table => 'fe_users'`, `maxitems => 1` and `default => 0`, plus one item
with value `0` so the field can be cleared. `DefaultTcaSchema` turns that
combination — `selectSingle` with a `foreign_table` and integer-only items —
into `int(11) unsigned DEFAULT 0 NOT NULL`, which is a plain scalar and **not**
an Extbase relation. That is deliberate: `Extbase\Domain\Model\FrontendUser` was
removed in v12.0, and the model therefore carries `int $feUser`, which Extbase
maps to this column through its default camelCase to underscore conversion.

The scalar is also what keeps the ownership resolver pluggable. The upstream
migration target resolves ownership through an n:m table rather than a column,
so the resolver interface speaks in owned sets and this column is only how *this*
extension happens to implement it.
→ [Authorization](authorization.md)

### `hidden` is a column, but not one we declare

`ctrl.enablecolumns.disabled => 'hidden'` creates the database column, and
`TcaEnrichment::enrichDisabledField()` additionally injects a matching
`columns['hidden']` definition into the prepared TCA when the table does not
declare one (`TcaEnrichment.php:48-70`). Extbase's `DataMapFactory` reads the
prepared TCA, not our files, so a `bool $hidden` model property maps correctly
even though no `hidden` entry appears in any file in `Configuration/TCA/`.

This matters because the edit plugin has to show hidden relation records and
toggle them. Do not "fix" the apparently missing entry by declaring one — that
replaces core's version-appropriate label and configuration with a hand-written
copy, which is exactly what
[never hand-write a system column](#never-hand-write-a-system-column) warns
against.

## No version conditional is needed, anywhere

This is the headline result, and it is worth stating plainly because
[Core version aware code](../architecture/core-version-aware-code.md#configuration-is-the-exception)
names configuration as the *one* place where a version conditional is allowed.
It is an exception, not a licence: it exists because TCA is loaded from a fixed
path and cannot be split into `Core13/`/`Core14/`. We went looking for a
difference that would need it, and did not find one.

Every candidate resolves to the same rule — **write the v14 shape; v13 either
behaves identically or ignores the key**:

| Candidate                       | Conditional? | Why not                                                                                                    |
|---------------------------------|--------------|------------------------------------------------------------------------------------------------------------|
| `ctrl.searchFields`             | no           | omit it; v13 then falls back to "all searchable fields", which is the v14 default                          |
| `config.searchable`             | no           | v14-only key, silently ignored by v13's field types                                                        |
| `ctrl.versioningWS` on children | no           | required on both versions                                                                                  |
| System column labels            | no           | never hand-written; core's auto-creation picks the right label per version                                 |
| Tab labels in `showitem`        | no           | the long `LLL:EXT:core/…/locallang_tabs.xlf:*` form is valid and undeprecated on both                      |
| `relationship => 'manyToOne'`   | no           | `TcaPreparation::configureRelationshipToOne()` exists in both and hard-sets `maxitems = 1`                 |
| `dbType => 'date'` + `nullable` | no           | same effective default on both; v13 inlines the rule in `DefaultTcaSchema`, v14 moved it to the field type |
| `type` column default           | no           | the one real divergence, and it is pinned by a two-line `ext_tables.sql` rather than by a TCA branch       |

So the three TCA files are plain `return [...]` with no branch and no
`Typo3Version` import. That is the smallest surface: a conditional added "for
safety" is a conditional that has to be maintained and tested on two versions
forever.

### The guard to use when one does become necessary

A future field may genuinely need it — the realistic triggers are
`format => 'datetimesec'` (v14.1+), `types[…]['title']` (v14.0+), or a v15
removal. The shape is fixed: build the array, apply the difference to the
**finished** array, return it.

```php
<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

$tca = [
    'ctrl' => [ /* … */ ],
    'columns' => [ /* … */ ],
    'types' => [ /* … */ ],
    'palettes' => [ /* … */ ],
];

// @todo Remove together with TYPO3 v13 support. `<key>` is v14-only; see TYPO3
//       changelog #<issue> "<title>".
if ((new Typo3Version())->getMajorVersion() >= 14) {
    $tca['columns']['<field>']['config']['<key>'] = '<value>';
}

return $tca;
```

Four rules for that block:

1. **Test the running major version, not `class_exists()`/`method_exists()`.**
   TCA is a data structure; there is no class to probe, and a feature probe
   cannot tell 14.0 from 14.1. `Configuration/Services.php` selects the
   `Core<major>/` directory the same way.
2. **Mutate the local `$tca` array; never read `$GLOBALS['TCA']`.** The backwards
   compatibility for reading it in base TCA files was removed in v14 —
   changelog **#107328 "$GLOBALS['TCA'] in base TCA files"**. It forbids
   *reading* the global there; it does not forbid a conditional, so building and
   returning a version dependent array stays legal.
3. **`>= 14`, never `=== 14`**, so v15 does not silently drop into the v13
   branch.
4. **Always a `@todo`** naming the removal condition plus the changelog issue
   number *and* title. A version switch without an exit condition becomes
   permanent.

## What must be omitted

Three `ctrl` options are removed by TCA migrations whose messages go through
`trigger_error(…, E_USER_DEPRECATED)` — `TcaFactory.php:174` on v14,
`TcaFactory.php:176` on v13. Our
[test suites fail on deprecations](../testing/phpunit-configuration.md#strictness-policy),
so any of them would turn a functional run red.

| Option              | Changelog                                                      | v13                           | v14                           |
|---------------------|----------------------------------------------------------------|-------------------------------|-------------------------------|
| `ctrl.searchFields` | Breaking **#106972** "TCA control option searchFields removed" | still evaluated               | removed, migrated, deprecated |
| `ctrl.cruser_id`    | migrated away on both versions                                 | removed, migrated, deprecated | removed, migrated, deprecated |
| `ctrl.is_static`    | Breaking **#106863**                                           | no migration, inert           | removed, migrated, deprecated |

`searchFields` is the interesting one, because dropping an option that v13 still
evaluates *usually* changes v13 behaviour — which is exactly the case the
architecture page uses to argue for a guard. Here it does not: v13's
`SearchableSchemaFieldsCollector.php:39-44` treats an empty `searchFields` as
"return all searchable fields", which is precisely v14's default after
Feature #106972 replaced the option with the per-field `config.searchable` flag.
Omitting it is therefore behaviour preserving on both versions, and the guard
would be a guard around nothing.

`config.searchable => false` may be written unconditionally where a field should
be excluded from search: v13's field types return a hardcoded `true` from
`isSearchable()` and never look at the key.

## Inline children of a workspace-aware parent

`ctrl.versioningWS => true` is **mandatory on the children**, not merely
advisable. Deprecation **#106821 "Workspace aware inline child tables are
enforced"** has v14 auto-add it through
`TcaMigration::addWorkspaceAwarenessToInlineChildren()` and emit a deprecation
while doing so — same `trigger_error` path as above. The changelog is explicit
that the combination "parent workspace aware, child not" is unsupported in
inline `foreign_table` setups regardless of whether `workspaces` is installed,
and that declaring it makes the schema analyzer add the `t3ver_*` columns by
itself.

On v13 the key is simply required in the ordinary way. There is nothing to
guard.

## Never hand-write a system column

`starttime`, `endtime`, `fe_group` and `l10n_parent` are the ones that tempt
people, because copy-pasting a `columns` entry from an older extension still
"works". It does not, on v14.1 and up.

Those historic entries carry `LLL:EXT:core/…/locallang_general.xlf:LGL.starttime`
and friends. `LGL.starttime`, `LGL.endtime`, `LGL.fe_group` and `LGL.l18n_parent`
are all marked `x-unused-since="14.0"` in that file, and Deprecation **#108086
"Raise deprecation error on using deprecated labels"** (14.1) makes TYPO3 raise
`E_USER_DEPRECATED` the first time such a label is written to the localization
cache. A hand-written column definition therefore fails our suites on v14.1+,
and does so from a file that looks entirely conventional.

Core's own auto-creation has no such problem: `TcaEnrichment` picks
`LGL.*` on v13 and the `core.db.general:*` short form on v14, per version, by
itself. Feature #104311 (v13.3) added that auto-creation of the `columns`
definitions; Feature #101553 (v13.0) had already added the auto-creation of the
database columns. Both are available on every version we support. If a system
column really must be declared by hand, give it a label from our own XLF — never
an `LGL.*` one.

There is a second reason, unrelated to labels. Each auto-created index is guarded
by the flag that the corresponding column was auto-created:

| Index                 | Fields                          | Guarded by                                             | v13 | v14 |
|-----------------------|---------------------------------|--------------------------------------------------------|-----|-----|
| `parent`              | `pid, deleted, hidden`          | `pid` was auto-created                                 | yes | yes |
| `language_identifier` | `l10n_parent, sys_language_uid` | `sys_language_uid` and `l10n_parent` were auto-created | no  | yes |
| `translation_source`  | `l10n_source`                   | `l10n_source` was auto-created                         | yes | yes |
| `t3ver_oid`           | `t3ver_oid, t3ver_wsid`         | workspace columns were auto-created                    | yes | yes |

Declaring `pid` in `ext_tables.sql` silently drops `parent`; declaring
`sys_language_uid` silently drops the v14 `language_identifier`. Nothing warns.
The index is simply absent, and the first symptom is a slow query on a large
table.

## `ext_tables.sql` is two columns long

Exactly one auto-generated definition differs between the versions, and it is
the `type` column of both children. For a `type=select` that maps to a string
column, v13 hardcodes the default:

```php
// v13 DefaultTcaSchema
$tables[$tableName]->addColumn($this->quote($fieldName), Types::STRING, [
    'notnull' => true,
    'default' => '',
    'length' => $dbFieldLength > 0 ? $dbFieldLength : 255,
]);
```

while v14 honours the TCA `default`:

```php
// v14 DefaultTcaSchema
$defaultValue = $fieldType->getDefaultValue();
$tables[$tableName]->addColumn($this->quote($fieldName), Types::STRING, [
    'notnull' => !$itemsContainNull,
    'default' => $itemsContainNull && $defaultValue === null ? null : (string)($defaultValue ?? ''),
    'length' => $dbFieldLength > 0 ? $dbFieldLength : 255,
]);
```

So `'default' => 'others'` yields `DEFAULT ''` on v13 and `DEFAULT 'others'` on
v14. That is a real difference in stored data, not a cosmetic one, and it is
fixed without touching the TCA: an explicit `ext_tables.sql` definition always
wins over auto-creation (Feature #101553, "Explicit definition in
`ext_tables.sql` always take precedence over auto-magic"). Two lines, both
versions identical:

```sql
#
# Only the columns whose auto-generated definition differs between TYPO3 v13 and
# v14 are pinned here. Explicit definitions always win over auto-creation --
# see TYPO3 changelog #101553 "Auto-create DB fields from TCA columns".
#
# Everything else (uid, pid, tstamp, crdate, deleted, hidden, starttime,
# endtime, sorting, sys_language_uid, l10n_parent, l10n_source, l10n_state,
# l10n_diffsource, t3ver_*, the inline parent pointers and every business
# column) is generated from TCA by DefaultTcaSchema and must NOT be repeated
# here: repeating pid or sys_language_uid also suppresses the auto-created
# `parent` and `language_identifier` indexes.
#

#
# Table structure for table 'tx_modernextbasefrontendedit_domain_model_address'
#
CREATE TABLE tx_modernextbasefrontendedit_domain_model_address (
	# TCA type=select auto-creation uses DEFAULT '' on v13 but the TCA 'default'
	# on v14. Pinned here so both versions agree.
	type varchar(150) DEFAULT 'others' NOT NULL
);

#
# Table structure for table 'tx_modernextbasefrontendedit_domain_model_email'
#
CREATE TABLE tx_modernextbasefrontendedit_domain_model_email (
	type varchar(150) DEFAULT 'others' NOT NULL
);
```

## `bio`: nullable `longtext`, `''` enforced in the model

`bio` has no `ext_tables.sql` line, and deliberately not the
`longtext DEFAULT '' NOT NULL` that reflex would produce. MySQL rejects a literal
`DEFAULT` on `BLOB`/`TEXT`/`JSON` columns (error 1101), and Doctrine's
`AbstractPlatform::getDefaultValueDeclarationSQL()` emits `DEFAULT '…'`
unconditionally for string-ish types — it does not special-case the platform. The
statement would therefore be refused and the `-d mysql` functional run would die
at schema setup.

The auto-generated column is `TEXT` with `notnull => false` and no length, which
Doctrine's MySQL platform maps to `longtext`; PostgreSQL gets `TEXT DEFAULT NULL`
and SQLite `CLOB DEFAULT NULL`. The `''` invariant belongs in the domain model
(`protected string $bio = '';`), not in the schema.

Core does exactly the same for its own `ctrl.descriptionColumn` and `l10n_state`
columns — both `TEXT`, `notnull => false` — and its `ext_tables.sql` carries
`longtext NOT NULL` twice but never a default on a TEXT column. Following that is
not a workaround, it is the portable shape.

The alternative, `bio longtext NOT NULL` without a default, is defensible if a
`NOT NULL` invariant is non-negotiable, but every CSV fixture then has to carry
the column or the import fails on PostgreSQL and on MySQL in strict mode. Not
worth it.

## `birthday`: `dbType => 'date'` **and** `nullable => true`

State this as a rule rather than a footnote, because it costs one green
`-d sqlite` run and a red `-d postgres` one to learn otherwise:

> **A native date/datetime column that is not nullable cannot survive the
> PostgreSQL functional run.**

`dbType` accepts `date`, `datetime` and `time`, and the string is handed straight
to Doctrine as the column type, so `dbType => 'date'` gives a native `DATE` on
every platform. A birthday is a date, not an instant, so that is the right
storage. But the "empty" value of a *non-nullable* native date field in TYPO3 is
the literal `'0000-00-00'` (`QueryHelper.php:240-243`, consumed by
`DateTimeFactory`). PostgreSQL rejects that value outright. SQLite and MariaDB do
not, which is why the mistake stays invisible until the matrix widens.

Native datetime fields are nullable by default on both versions — v14 in
`DateTimeFieldType::isNullable()`, v13 inlined in `DefaultTcaSchema` as
`$nullable = $fieldConfig['config']['nullable'] ?? true;`. Writing
`nullable => true` explicitly is still worth it: it documents intent and does not
rest on a defaulted-true that a future core release could tighten.

`format => 'date'` is likewise written explicitly. v14 forces `format` to `date`
whenever `dbType => 'date'` is set, v13 only did so for `time`/`timesec`; spelling
it out makes that divergence moot. `format => 'datetimesec'` is v14.1+ and must
not be used.

## Manual sorting needs both keys

Manual sorting of the children is configured twice, on purpose:

- `ctrl.sortby => 'sorting'` on the **child** table, which is what auto-creates
  the `sorting` column. `foreign_sortby` on the parent creates nothing.
- `foreign_sortby => 'sorting'` on the **parent** inline column.

At runtime `foreign_sortby` wins: `RelationHandler.php:1035-1041` takes it if
present and only otherwise falls back to the child's `SortByField` capability.
The FormEngine up/down handles appear when *either* is set
(`InlineRecordContainer`), so the backend would work with one of them alone. The
write path does not. `foreign_sortby` on the parent column is specifically what
the Extbase write path reads before it will write a sorting value at all — see
[Persistence and sorting](persistence-and-sorting.md), which covers why
reordering means detach-all plus re-attach, and why `attach()` on an already
contained object reorders nothing.

Both keys point at the same column, so there is no conflict, and the explicit
`foreign_sortby` survives the child later gaining a different `ctrl.sortby`.

## `foreign_table_field` yes, `foreign_match_fields` no

Neither `foreign_sortby`, `foreign_table_field` nor `foreign_match_fields` was
touched by any v13.x or v14.x changelog entry, and all three are still read at
runtime by `RelationHandler` and Extbase's `ColumnMapFactory`. **`foreign_table_field`
is unchanged in v14** and is kept: the column is auto-created as
`varchar(255) DEFAULT '' NOT NULL` under the same condition as the
`foreign_field` pointer, it costs one column per child row, and it makes the
child reusable from a second parent field later without a schema migration.

`foreign_match_fields` is avoided. Its columns are **not** auto-created —
`DefaultTcaSchema` handles `foreign_field` and `foreign_table_field` and nothing
else — so each one would have to be hand-declared in `ext_tables.sql`, which is
the thing this schema is trying not to do. And it buys nothing here: `address`
and `email` are two separate tables, each used by exactly one parent field, so
there is nothing to disambiguate.

Both pointer columns are declared as `type => 'passthrough'` in the child TCA.
That is deliberate rather than an omission: `DefaultTcaSchema` treats a
`passthrough` column the same as an absent one and still auto-creates the
database column, so the pointers are visible in the TCA — which is where the next
reader looks — without suppressing anything.

## Complete TCA

Labels reference `EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf`,
which is created alongside. The identifiers are template identifiers and are
rewritten on initialization.

Note that the language and access palettes have to be spelled out.
`TcaPreparation::addSystemFieldsToShowitemTypes()` only processes `tt_content` on
**both** versions — the `@todo` in v13 saying this "might change in v14" is still
there in 14.3 — so nothing is added to `showitem` automatically for our tables.

### `Configuration/TCA/tx_modernextbasefrontendedit_domain_model_profile.php`

```php
<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_profile',
        'label' => 'shortname',
        'label_alt' => 'lastname,firstname',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'versioningWS' => true,
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource' => 'l10n_source',
        'default_sortby' => 'lastname, firstname',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'typeicon_classes' => [
            'default' => 'mimetypes-x-content-text',
        ],
        // Deliberately no 'searchFields': removed in v14 (#106972) and
        // equivalent to its absence on v13.
    ],
    'columns' => [
        'shortname' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_profile.shortname',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'firstname' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_profile.firstname',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
            ],
        ],
        'lastname' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_profile.lastname',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
            ],
        ],
        'image' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_profile.image',
            'config' => [
                'type' => 'file',
                'relationship' => 'manyToOne',
                'allowed' => 'common-image-types',
                'appearance' => [
                    'createNewRelationLinkTitle' => 'LLL:EXT:core/Resources/Private/Language/locallang_tca.xlf:sys_file_reference',
                    'showPossibleLocalizationRecords' => true,
                ],
            ],
        ],
        'birthday' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_profile.birthday',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
                'dbType' => 'date',
                'nullable' => true,
                'default' => null,
                'size' => 12,
            ],
        ],
        'bio' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_profile.bio',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 15,
            ],
        ],
        'addresses' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_profile.addresses',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_modernextbasefrontendedit_domain_model_address',
                'foreign_field' => 'profile',
                'foreign_table_field' => 'tablenames',
                'foreign_sortby' => 'sorting',
                'maxitems' => 99,
                'appearance' => [
                    'collapseAll' => true,
                    'expandSingle' => true,
                    'useSortable' => true,
                    'showSynchronizationLink' => true,
                    'showAllLocalizationLink' => true,
                    'showPossibleLocalizationRecords' => true,
                ],
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
        'emails' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_profile.emails',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_modernextbasefrontendedit_domain_model_email',
                'foreign_field' => 'profile',
                'foreign_table_field' => 'tablenames',
                'foreign_sortby' => 'sorting',
                'maxitems' => 99,
                'appearance' => [
                    'collapseAll' => true,
                    'expandSingle' => true,
                    'useSortable' => true,
                    'showSynchronizationLink' => true,
                    'showAllLocalizationLink' => true,
                    'showPossibleLocalizationRecords' => true,
                ],
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    shortname, firstname, lastname, birthday, image,
                --div--;LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tabs.contact,
                    addresses, emails,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:text,
                    bio,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:language,
                    --palette--;;language,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden, --palette--;;timeRestriction,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:extended,
            ',
        ],
    ],
    'palettes' => [
        'language' => ['showitem' => 'sys_language_uid, l10n_parent'],
        'timeRestriction' => ['showitem' => 'starttime, endtime'],
    ],
];
```

### `Configuration/TCA/tx_modernextbasefrontendedit_domain_model_address.php`

```php
<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_address',
        'label' => 'line1',
        'label_alt' => 'type',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // Mandatory: the parent table is workspace aware. See #106821.
        'versioningWS' => true,
        'sortby' => 'sorting',
        'hideTable' => true,
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource' => 'l10n_source',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'typeicon_classes' => [
            'default' => 'mimetypes-x-content-text',
        ],
    ],
    'columns' => [
        'profile' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'tablenames' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'type' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_address.type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'dbFieldLength' => 150,
                'default' => 'others',
                'items' => [
                    ['label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_address.type.home', 'value' => 'home'],
                    ['label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_address.type.work', 'value' => 'work'],
                    ['label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_address.type.others', 'value' => 'others'],
                ],
            ],
        ],
        'line1' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_address.line1',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
        'line2' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_address.line2',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    type, line1, line2,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:language,
                    --palette--;;language,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden, --palette--;;timeRestriction,
            ',
        ],
    ],
    'palettes' => [
        'language' => ['showitem' => 'sys_language_uid, l10n_parent'],
        'timeRestriction' => ['showitem' => 'starttime, endtime'],
    ],
];
```

### `Configuration/TCA/tx_modernextbasefrontendedit_domain_model_email.php`

```php
<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_email',
        'label' => 'email',
        'label_alt' => 'type',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // Mandatory: the parent table is workspace aware. See #106821.
        'versioningWS' => true,
        'sortby' => 'sorting',
        'hideTable' => true,
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource' => 'l10n_source',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'typeicon_classes' => [
            'default' => 'mimetypes-x-content-text',
        ],
    ],
    'columns' => [
        'profile' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'tablenames' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'type' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_email.type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'dbFieldLength' => 150,
                'default' => 'others',
                'items' => [
                    ['label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_email.type.private', 'value' => 'private'],
                    ['label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_email.type.business', 'value' => 'business'],
                    ['label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_email.type.others', 'value' => 'others'],
                ],
            ],
        ],
        'email' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_email.email',
            'config' => [
                'type' => 'email',
                'size' => 40,
                'required' => true,
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    type, email,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:language,
                    --palette--;;language,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden, --palette--;;timeRestriction,
            ',
        ],
    ],
    'palettes' => [
        'language' => ['showitem' => 'sys_language_uid, l10n_parent'],
        'timeRestriction' => ['showitem' => 'starttime, endtime'],
    ],
];
```

## One thing that belongs in `Documentation/`, not here

`birthday` maps to `?\DateTime` through Extbase's `DataMapper::mapDateTime()`,
and that path is gated on the `extbase.consistentDateTimeHandling` feature flag:

| Source                             | Value   |
|------------------------------------|---------|
| v13 `DefaultConfiguration.php:86`  | `false` |
| v13 `FactoryConfiguration.php:29`  | `true`  |
| v14 `DefaultConfiguration.php:104` | `true`  |

`FactoryConfiguration.php` is what new v13 installations get, and it is also what
`typo3/testing-framework` builds its instance configuration from — so the flag is
`true` under test on both versions, and our functional tests will never see the
old behaviour. **Existing v13 production installations that were upgraded rather
than freshly installed still have it `false`**, which changes how a `\DateTime`
comes back out of the database, including whether it carries a named timezone or
an offset.

That is a deployment note for integrators, so it belongs in `Documentation/`,
with a pointer to Important **#106467 "Align Extbase DateTime handling to
FormEngine and DataHandler"**. It is explicitly *not* a reason for a version
conditional: the flag is a site setting, not a core version difference, and
branching on the core major would get it wrong for exactly the installations
that need the note.

## Not covered here

- **Nothing has been executed.** The generated column shapes above were derived
  by reading `DefaultTcaSchema` and the Doctrine platform code. They must be
  confirmed once against `-d mariadb`, `-d postgres` and `-d sqlite` when the TCA
  lands, and that run is part of the implementing change, not of this page.
- **`behaviour.allowLanguageSynchronization`** on the two inline columns follows
  core practice for `pages.media`; it presumes connected-mode translations.
  Confirm the intended translation workflow before it ships.
- **Extbase identity map changes in v14.2** (Important #93765, the identity map
  is now language aware) were noticed but not analysed. They affect how
  translated children behave within one request and deserve their own look when
  the models are written.

## See also

- [Modern frontend editing](Index.md) — the other design pages of this feature.
- [Persistence and sorting](persistence-and-sorting.md) — why `foreign_sortby`
  is what the write path reads, and how reordering and orphan removal are
  handled.
- [Image handling](image-handling.md) — what the single `type=file` column
  becomes on the model side.
- [Core version aware code](../architecture/core-version-aware-code.md) — the
  `Core13/`/`Core14/` split and the configuration exception this page did not
  need.
- [Class design](../architecture/class-design.md) — data objects are not
  services, and `#[Exclude]` on Extbase models.
- [Functional tests](../testing/functional-tests.md) and
  [Site based tests](../testing/site-based-tests.md) — the database matrix the
  `birthday` and `bio` decisions are made for.
- [Changelog and documentation](../workflow/changelog-and-documentation.md) —
  where the `extbase.consistentDateTimeHandling` note goes.
