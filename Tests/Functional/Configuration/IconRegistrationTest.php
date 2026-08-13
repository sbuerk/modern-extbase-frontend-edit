<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Imaging\IconSize;

/**
 * That the icons this extension registers survive being rendered.
 *
 * None of this is ceremony. Every assertion below covers something that is
 * outside this extension's control and that would fail silently:
 *
 * - **The registration itself.** `Configuration/Icons.php` is read by
 *   `IconRegistry` at boot; a typo in a path produces a registered identifier
 *   whose file does not exist, and the icon renders as an empty string.
 * - **The sanitiser.** Inline markup goes through
 *   `SvgDocumentFactory::fromStringAndSanitize()`, which is entitled to drop
 *   attributes it does not like. If it dropped `stroke="currentColor"` every
 *   glyph would render black on a filled blue button, and nothing else in this
 *   repository would notice — the acceptance suite asserts that an icon is
 *   present and `aria-hidden`, never what colour it is.
 * - **The provider choice.** `SvgIconProvider` inlines the file;
 *   `SvgSpriteIconProvider`, which core's own action icons use, emits a `<use>`
 *   into an external sprite instead. The two are one word apart in
 *   `Configuration/Icons.php` and produce completely different markup.
 *
 * `IconSize` is used rather than the string constants it replaced: the enum
 * exists since TYPO3 v13.0 (Feature #101475), so it is available on both
 * supported versions, and the constants are deprecated.
 */
final class IconRegistrationTest extends AbstractFunctionalTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function registeredIdentifiers(): array
    {
        /** @var array<string, array{provider: class-string, source: string}> $declaration */
        $declaration = require __DIR__ . '/../../../Configuration/Icons.php';
        $cases = [];
        foreach (array_keys($declaration) as $identifier) {
            $identifier = (string)$identifier;
            $cases[$identifier] = [$identifier];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('registeredIdentifiers')]
    public function everyDeclaredIconIsRegistered(string $identifier): void
    {
        $this->assertTrue(
            $this->get(IconRegistry::class)->isRegistered($identifier),
            sprintf('The icon "%s" is declared in Configuration/Icons.php but not registered.', $identifier),
        );
    }

    #[Test]
    #[DataProvider('registeredIdentifiers')]
    public function everyIconRendersInlineSvgThatFollowsTheTextColour(string $identifier): void
    {
        $markup = $this->get(IconFactory::class)
            ->getIcon($identifier, IconSize::SMALL)
            // "inline" is the alternative markup identifier the SVG providers
            // register. The constant that names it lives on the @internal
            // AbstractSvgIconProvider, so the literal is used rather than an
            // import of a class this extension is not entitled to depend on.
            ->getAlternativeMarkup('inline');

        $this->assertStringContainsString('<svg', $markup);
        $this->assertStringContainsString('<path', $markup);

        // The whole reason these icons are not core's sprite icons.
        $this->assertStringContainsString('currentColor', $markup);

        /*
         * `focusable="false"` is deliberately **not** asserted, and the icon
         * files deliberately do not carry it.
         *
         * It was tried. Core's SVG sanitiser does not allow the attribute, so
         * `fromStringAndSanitize()` strips it and no icon resolved through
         * `IconRegistry` can carry it however it is written on disk. That is a
         * property of the pipeline, not something this extension can decide.
         *
         * It costs nothing here. `focusable` exists for Internet Explorer and
         * the pre-Chromium Edge, both far below the browser floor the import map
         * mechanism sets (Chrome 89 / Firefox 108 / Safari 16.4), and no browser
         * in that range makes an `<svg>` focusable by default. The accessibility
         * tree is covered regardless by the `aria-hidden` the component puts on
         * the wrapping span, which no sanitiser sees.
         */

        // And the whole reason they are not fetched: an inline glyph refers to
        // nothing, so no request and no CSP directive is involved.
        $this->assertStringNotContainsString('<use', $markup);
        $this->assertStringNotContainsString('xlink:href', $markup);
    }
}
