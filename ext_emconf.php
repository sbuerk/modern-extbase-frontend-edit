<?php

$EM_CONF['modern_extbase_frontend_edit'] = [
    'title' => 'Modern Extbase Frontend Edit',
    'description' => 'TYPO3 CMS extension modern_extbase_frontend_edit.',
    'version' => '1.0.1',
    'category' => 'misc',
    'state' => 'alpha',
    'author' => 'sbuerk',
    'author_email' => '',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '13.4.0-14.3.99',
            'core' => '13.4.0-14.3.99',
            'extbase' => '13.4.0-14.3.99',
            'fluid' => '13.4.0-14.3.99',
            // Required because the plugin rendering definition that
            // ExtensionUtility::configurePlugin() generates references
            // "lib.contentElement" — see the reasoning in ext_localconf.php.
            'fluid_styled_content' => '13.4.0-14.3.99',
            'frontend' => '13.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
