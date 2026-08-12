<?php

declare(strict_types=1);

/**
 * Import map entries for the frontend assets of this extension.
 *
 * `dependencies` names `core` rather than `backend`, and that is the whole
 * reason `lit` never has to be bundled: `EXT:core`'s own module map declares
 * `lit`, `lit-element` and `lit-html`, so depending on `core` makes those bare
 * specifiers resolvable from a frontend page as well. Shipping a second copy of
 * lit inside our bundle would put two runtimes on one page, which breaks custom
 * element registration rather than merely wasting bytes.
 *
 * Import maps are not backend only. `PageRenderer` emits the map regardless of
 * application type — only the nonce attribute is gated on the backend — so the
 * same declaration serves both. See the "Frontend assets" page of the developer
 * documentation for the source references behind that.
 *
 * No `tags` are declared. Those exist so the backend can eagerly load whole
 * groups of modules; a frontend page loads exactly the one module its template
 * asks for.
 */
return [
    'dependencies' => [
        'core',
    ],
    'imports' => [
        '@sbuerk/modern-extbase-frontend-edit/' => 'EXT:modern_extbase_frontend_edit/Resources/Public/JavaScript/',
    ],
];
