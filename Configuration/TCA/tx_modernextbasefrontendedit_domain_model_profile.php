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
        // Ownership pointer. A single value select on 'fe_users' with
        // 'renderType' => 'selectSingle' and 'maxitems' => 1 is stored as a
        // scalar int column, so the model carries a plain int and the
        // ownership comparison stays "int === int" — the column is an
        // implementation detail behind the ownership resolver interface.
        'fe_user' => [
            'exclude' => true,
            'label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_profile.fe_user',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'fe_users',
                'maxitems' => 1,
                'default' => 0,
                'items' => [
                    ['label' => 'LLL:EXT:modern_extbase_frontend_edit/Resources/Private/Language/locallang_db.xlf:tx_modernextbasefrontendedit_domain_model_profile.fe_user.none', 'value' => 0],
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
                    fe_user, hidden, --palette--;;timeRestriction,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:extended,
            ',
        ],
    ],
    'palettes' => [
        'language' => ['showitem' => 'sys_language_uid, l10n_parent'],
        'timeRestriction' => ['showitem' => 'starttime, endtime'],
    ],
];
