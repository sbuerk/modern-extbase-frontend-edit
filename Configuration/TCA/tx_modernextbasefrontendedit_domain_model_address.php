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
