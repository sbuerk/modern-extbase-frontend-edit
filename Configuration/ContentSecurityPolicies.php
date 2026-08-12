<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Type\Map;

/**
 * What the editing surface needs from a Content Security Policy, and nothing
 * more.
 *
 * Frontend CSP is **off by default** on both target versions: neither
 * `security.frontend.enforceContentSecurityPolicy` nor its report-only sibling
 * is enabled out of the box, and neither is in the always-active set. A site
 * turns it on with either feature flag, or per site in `csp.yaml` — and the
 * `csp.yaml` route needs no flag at all, which is why "the integrator has not
 * enabled a feature" is not a safe assumption to build on.
 *
 * ## What these four declarations actually cost
 *
 * All four descend from `default-src`, and `MutationMode::Extend` inherits the
 * ancestor before appending, so with core's own `default-src 'self'` in place
 * each of them resolves to exactly `'self'`. `Policy::prepare()` then deletes a
 * directive whose source set is identical to its ancestor's.
 *
 * Three of the four disappear that way. Measured against a rendered response,
 * with this file and without it, the difference in the emitted header is one
 * directive:
 *
 *     style-src 'self' 'report-sample'
 *
 * It survives folding only because `'report-sample'` is appended to a directive
 * that was declared and not to `default-src`, which makes the two source sets
 * differ by a token that grants nothing. `script-src` and `img-src` are in the
 * header either way — core declares both — and `connect-src` folds away
 * entirely.
 *
 * So the cost is one redundant directive that permits exactly what
 * `default-src` already permits. What it buys is the case where that stops
 * being true: a site that narrows `default-src` — to `'none'`, or to a host —
 * applies its own mutations *after* the ones declared by packages, so all four
 * stop being identical to their ancestor and survive into the header. The
 * editing surface keeps working instead of failing with four console errors and
 * no explanation.
 *
 * ## What is deliberately absent
 *
 * Each of these was checked against the shipped assets rather than omitted by
 * oversight:
 *
 * - `style-src 'unsafe-inline'` — lit installs component styles through
 *   `adoptedStyleSheets`, which produces no `<style>` element at all. Its
 *   fallback branch is what would need this, and that branch is unreachable for
 *   every browser able to resolve an import map in the first place. The
 *   backend's own policy does grant it, for the older browsers the backend
 *   still supports; the frontend policy of `EXT:frontend` does not.
 * - `img-src data:` and `img-src blob:` — there is no client side image
 *   preview. The chosen file goes straight into a `FormData`, and what is
 *   displayed afterwards is the stored file, served by FAL from this origin.
 * - `form-action` — there is no `<form>`. Every control is a button of
 *   `type="button"` and every write is a `fetch()`.
 * - `script-src 'unsafe-eval'`, `worker-src`, `font-src`, `frame-src`,
 *   `base-uri`, `object-src` — nothing in the assets uses any of them.
 *
 * No nonce is requested either. The one inline script on the page is the import
 * map, which is core's own and which core covers with a hash of its own content
 * in the frontend.
 *
 * ## Turning it off
 *
 * An integrator who wants none of this can drop it per site, by package name:
 *
 *     # config/sites/<identifier>/csp.yaml
 *     enforce:
 *       packages:
 *         '*': true
 *         sbuerk/modern-extbase-frontend-edit: false
 *
 * Note that `inheritDefault: false` is **not** the way to do it: that drops the
 * generic frontend mutations of *every* package, core's `default-src 'self'`
 * included, and a site using it has to grant the four sources below itself.
 */
return Map::fromEntries([
    Scope::frontend(),
    new MutationCollection(
        // The editing surface is an external ES module loaded through the
        // import map. No inline script is emitted by this extension.
        new Mutation(MutationMode::Extend, Directive::ScriptSrc, SourceKeyword::self),
        // One `<link rel="stylesheet">` from `f:asset.css`. The component's own
        // styles never reach this directive - see above.
        new Mutation(MutationMode::Extend, Directive::StyleSrc, SourceKeyword::self),
        // The write endpoints, reached with `fetch()` at relative URLs built
        // server side.
        new Mutation(MutationMode::Extend, Directive::ConnectSrc, SourceKeyword::self),
        // Stored profile images, served by FAL from this origin.
        new Mutation(MutationMode::Extend, Directive::ImgSrc, SourceKeyword::self),
    ),
]);
