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
