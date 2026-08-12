<?php

declare(strict_types=1);

use TESTS\WorkspaceFixture\Middleware\WorkspaceAspectFromTestingContext;

return [
    'frontend' => [
        'tests/workspace-fixture/workspace-aspect' => [
            'target' => WorkspaceAspectFromTestingContext::class,
            // Deeper in the stack than the backend user authenticator, which is
            // the middleware that sets this aspect in a real request and would
            // otherwise overwrite it again.
            'after' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
    ],
];
