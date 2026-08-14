<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Styling;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The resting edge of a control has to be visible, and this measures it.
 *
 * ## What is being pinned, and why arithmetic rather than a picture
 *
 * WCAG 2.2 success criterion 1.4.11 (Non-text Contrast, level AA) asks for 3:1
 * on the *visual information required to identify user interface components*.
 * For a button and for an input on this surface that information is the border:
 * the fill differs from the page by 1.1:1 to 1.3:1 in both schemes, so it is not
 * the fill that says a control is there. Nothing else does either.
 *
 * That makes the border a number, and a number is worth asserting. The visual
 * regression suite photographs these controls in both schemes and would not
 * notice the difference between a legible edge and an invisible one — it
 * compares a new rendering against an accepted one, and an accepted one can be
 * wrong. This file compares against a threshold instead, so it can fail on the
 * *first* rendering rather than only on a change to it.
 *
 * ## Why the shipped stylesheet and not the browser
 *
 * The values read here are the extension's own defaults: what a project gets
 * when it installs this extension and themes nothing. The acceptance suite
 * cannot see them, because every page it drives carries the development site
 * package and therefore the mapped values. The two are complementary and both
 * are needed:
 *
 * - this file fails when the shipped palette stops meeting the criterion,
 * - `Tests/Acceptance/Frontend/ControlContrast` fails when the *mapping* onto a
 *   theme does, which is a defect this file cannot see because nothing in the
 *   shipped file is wrong at that moment.
 *
 * Reading CSS with a regular expression is normally a bad idea and is a fine one
 * here for the reason `DesignTokenWiringChecker` gives: the file is generated,
 * every declaration in it is on its own line, and nothing outside a comment is
 * ambiguous.
 *
 * ## The ordering assertion is not decoration
 *
 * The three border roles form a scale, and the hover state is the step past the
 * resting one. Before the control role existed, one token drew both, and adding
 * a role that meets 3:1 without moving the emphasis role would have made the
 * hover edge the *weaker* of the two — the control would appear to go quiet
 * under the pointer. The ordering test is what would catch that, and it is the
 * half of this file most likely to earn its place later.
 */
final class ControlBorderContrastTest extends UnitTestCase
{
    /**
     * The criterion's threshold. Not rounded up to a comfortable number on
     * purpose: a value that only passes because the assertion is generous is a
     * value that fails for a reader.
     */
    private const MINIMUM_NON_TEXT_CONTRAST = 3.0;

    protected bool $resetSingletonInstances = false;

    /**
     * Both fills a control is drawn against. A control sits on the surface, and
     * on hover the surface becomes the sunken one while the border moves to the
     * emphasis role — so the resting border has to clear the threshold against
     * both, not only against the one it is usually seen on.
     *
     * @return array<string, array{0: string}>
     */
    public static function schemes(): array
    {
        return [
            'light' => ['light'],
            'dark' => ['dark'],
        ];
    }

    #[DataProvider('schemes')]
    #[Test]
    public function theRestingBorderOfAControlMeetsTheNonTextContrastMinimum(string $scheme): void
    {
        $tokens = $this->tokensOf($scheme);
        $border = $tokens['--frontend-edit-color-border-control'] ?? null;
        $this->assertNotNull($border, sprintf('the %s scheme declares a control border', $scheme));

        foreach (['--frontend-edit-color-surface', '--frontend-edit-color-surface-sunken'] as $fill) {
            $against = $tokens[$fill] ?? null;
            $this->assertNotNull($against, sprintf('the %s scheme declares %s', $scheme, $fill));

            $ratio = $this->contrastRatio($border, $against);
            $this->assertGreaterThanOrEqual(
                self::MINIMUM_NON_TEXT_CONTRAST,
                $ratio,
                sprintf(
                    'WCAG 1.4.11 asks for %.1f:1 between a control border and the fill it encloses. '
                    . 'In the %s scheme %s against %s (%s) is %.2f:1.',
                    self::MINIMUM_NON_TEXT_CONTRAST,
                    $scheme,
                    $border,
                    $fill,
                    $against,
                    $ratio,
                ),
            );
        }
    }

    /**
     * The scale, in order, against the fill a control is normally drawn on.
     *
     * Decoration is deliberately *below* the threshold — a separator held to a
     * control's contrast turns the surface into a stack of boxes — so this
     * asserts the ordering rather than a floor for all three.
     */
    #[DataProvider('schemes')]
    #[Test]
    public function theThreeBorderRolesAreOrderedByEmphasis(string $scheme): void
    {
        $tokens = $this->tokensOf($scheme);
        $surface = $tokens['--frontend-edit-color-surface'] ?? '';

        $decoration = $this->contrastRatio($tokens['--frontend-edit-color-border'] ?? '', $surface);
        $control = $this->contrastRatio($tokens['--frontend-edit-color-border-control'] ?? '', $surface);
        $emphasis = $this->contrastRatio($tokens['--frontend-edit-color-border-strong'] ?? '', $surface);

        $this->assertGreaterThan(
            $decoration,
            $control,
            sprintf('a control edge outranks a separator (%s scheme)', $scheme),
        );
        $this->assertGreaterThan(
            $control,
            $emphasis,
            sprintf(
                'the hover edge has to be the step past the resting one, or the control reads as going '
                . 'quiet under the pointer (%s scheme)',
                $scheme,
            ),
        );
    }

    /**
     * Proves the arithmetic below is the WCAG formula and not something that
     * merely returns large numbers, using the two ratios the specification
     * fixes: the extremes are 21:1 and a colour against itself is 1:1.
     */
    #[Test]
    public function theContrastFormulaAgreesWithTheTwoRatiosTheSpecificationFixes(): void
    {
        $this->assertEqualsWithDelta(21.0, $this->contrastRatio('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, $this->contrastRatio('#7d838a', '#7d838a'), 0.01);
    }

    /**
     * Every custom property in effect for one scheme.
     *
     * The light values are the declarations of the file; the dark ones are those
     * overlaid with the block behind `prefers-color-scheme: dark`, which is how
     * a browser resolves them and therefore the only reading that answers what a
     * visitor sees.
     *
     * @return array<string, string>
     */
    private function tokensOf(string $scheme): array
    {
        $css = $this->stylesheet();
        $dark = $this->darkSchemeBlock($css);
        $light = $this->declarations(str_replace($dark, '', $css));

        return $scheme === 'dark'
            ? array_merge($light, $this->declarations($dark))
            : $light;
    }

    /**
     * The text of the `prefers-color-scheme: dark` block, braces balanced.
     *
     * Scanned rather than matched with a regular expression because the block
     * contains nested rules: a non-greedy `.*?` would stop at the first closing
     * brace and silently return the first declaration only, which is a failure
     * that looks like a pass.
     */
    private function darkSchemeBlock(string $css): string
    {
        $start = strpos($css, '@media (prefers-color-scheme: dark)');
        $this->assertNotFalse($start, 'the shipped stylesheet carries a dark scheme block');

        $open = strpos($css, '{', $start);
        $this->assertNotFalse($open);

        $depth = 0;
        $length = strlen($css);
        for ($position = $open; $position < $length; $position++) {
            $depth += (int)($css[$position] === '{') - (int)($css[$position] === '}');
            if ($depth === 0) {
                return substr($css, $start, $position - $start + 1);
            }
        }

        $this->fail('the dark scheme block is never closed');
    }

    /**
     * @return array<string, string>
     */
    private function declarations(string $css): array
    {
        preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*(#[0-9a-fA-F]{3,8})\s*;/', $css, $matches, PREG_SET_ORDER);

        $declarations = [];
        foreach ($matches as $match) {
            $declarations[$match[1]] = strtolower($match[2]);
        }

        return $declarations;
    }

    private function stylesheet(): string
    {
        $path = dirname(__DIR__, 3) . '/Resources/Public/Css/frontend/frontend-edit.css';
        $css = file_get_contents($path);
        $this->assertIsString($css, sprintf('the shipped stylesheet is readable at %s', $path));

        return $css;
    }

    /**
     * The WCAG 2.2 contrast ratio of two `#rrggbb` colours.
     */
    private function contrastRatio(string $first, string $second): float
    {
        $a = $this->relativeLuminance($first);
        $b = $this->relativeLuminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * Relative luminance, per the WCAG definition — the sRGB channels linearised
     * and weighted. The weights are the specification's, not a simplification.
     */
    private function relativeLuminance(string $colour): float
    {
        $hex = ltrim($colour, '#');
        $this->assertSame(6, strlen($hex), sprintf('"%s" is a six digit hex colour', $colour));

        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
