<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The rules of `-s checkDesignTokenWiring`, on stylesheets small enough to
 * reason about.
 *
 * The checker had no test for six pull requests and was trusted because it was
 * green against the real files — which says only that the real files pass, not
 * that a broken one would fail. Three of its rules are impossible to exercise
 * that way at all without breaking the repository on purpose: the cycle guard,
 * the "mapping for a token the surface no longer has" case, and the distinction
 * between a mapping that repeats a literal and a token that is simply unwired.
 *
 * The last of those is the one worth stating. A literal in the mapping file is
 * *also* unwired by construction, and the checker deliberately reports it under
 * the more specific heading **only**, because two findings naming one token read
 * as two problems. That is a presentation decision living inside the algorithm,
 * so it is the kind of thing a later tidy-up removes without noticing.
 *
 * The checker is not namespaced and is not part of the shipped autoload map — it
 * is build tooling. `composer.json` lists it under `autoload-dev.classmap`,
 * which is what makes it reachable from here and resolvable for PHPStan.
 *
 * @see Build/Scripts/DesignTokenWiringChecker.php
 */
final class DesignTokenWiringCheckerTest extends UnitTestCase
{
    private const THEME = ['theme.css' => ':root { --c-accent: #2563a8; --space-1: 0.25rem; }'];

    #[Test]
    public function aTokenMappedOntoAThemeTokenIsWired(): void
    {
        $result = $this->check(
            ':where(x) { --frontend-edit-color-accent: #0a7bd4; }',
            'x { --frontend-edit-color-accent: var(--c-accent); }',
        );

        $this->assertSame([], $result['unwired']);
        $this->assertSame([], $result['literal']);
        $this->assertSame(['--frontend-edit-color-accent'], array_keys($result['tokens']));
    }

    /**
     * Mapping a derived token again would create the second copy the gate
     * exists to remove, so deriving has to count as wired on its own.
     */
    #[Test]
    public function aTokenDerivedFromAWiredTokenIsWired(): void
    {
        $result = $this->check(
            ':where(x) {
                --frontend-edit-space-xs: 0.25rem;
                --frontend-edit-gap-within: var(--frontend-edit-space-xs);
            }',
            'x { --frontend-edit-space-xs: var(--space-1); }',
        );

        $this->assertSame([], $result['unwired']);
    }

    #[Test]
    public function aTokenDerivedFromAnUnwiredTokenIsNotWired(): void
    {
        $result = $this->check(
            ':where(x) {
                --frontend-edit-space-xs: 0.25rem;
                --frontend-edit-gap-within: var(--frontend-edit-space-xs);
            }',
            'x { }',
        );

        $this->assertSame(
            ['--frontend-edit-gap-within', '--frontend-edit-space-xs'],
            $result['unwired'],
            'deriving from a second copy of a value produces a third',
        );
    }

    /**
     * `inherit` takes the host page's value, which is stronger than any mapping
     * could be — pointing it at a theme token would pin the surface to the
     * page's default inside a section that deliberately changed it.
     */
    #[Test]
    public function aTokenThatInheritsIsWired(): void
    {
        $result = $this->check(
            ':where(x) { --frontend-edit-font-family: inherit; }',
            'x { }',
        );

        $this->assertSame([], $result['unwired']);
    }

    #[Test]
    public function aTokenWithALiteralDefaultAndNoMappingIsUnwired(): void
    {
        $result = $this->check(
            ':where(x) { --frontend-edit-radius: 0.25rem; }',
            'x { }',
        );

        $this->assertSame(['--frontend-edit-radius'], $result['unwired']);
    }

    /**
     * The failure the gate was built for: it agrees with the theme today,
     * nothing connects the two, and they part in silence the day either moves.
     */
    #[Test]
    public function aMappingThatRepeatsALiteralIsReportedAsALiteralAndNotAlsoAsUnwired(): void
    {
        $result = $this->check(
            ':where(x) { --frontend-edit-space-xs: 0.25rem; }',
            'x { --frontend-edit-space-xs: 0.25rem; }',
        );

        $this->assertSame(['--frontend-edit-space-xs' => '0.25rem'], $result['literal']);
        $this->assertSame([], $result['unwired'], 'two findings naming one token read as two problems');
    }

    /**
     * A mapping that only mentions surface tokens is the surface talking to
     * itself in the site package's file, not a mapping.
     */
    #[Test]
    public function aMappingThatNamesOnlySurfaceTokensIsNotAMapping(): void
    {
        $result = $this->check(
            ':where(x) { --frontend-edit-radius: 0.25rem; --frontend-edit-radius-lg: 0.5rem; }',
            'x { --frontend-edit-radius-lg: var(--frontend-edit-radius); }',
        );

        $this->assertArrayHasKey('--frontend-edit-radius-lg', $result['literal']);
    }

    #[Test]
    public function aTokenReadButNeverDeclaredIsReported(): void
    {
        $result = $this->check(
            ':where(x) { --frontend-edit-radius: 0.25rem; }
             y { outline-color: var(--frontend-edit-outline-color); }',
            'x { --frontend-edit-radius: var(--space-1); }',
        );

        $this->assertSame(['--frontend-edit-outline-color'], $result['undeclared']);
    }

    #[Test]
    public function aMappingForATokenTheSurfaceNoLongerHasIsReported(): void
    {
        $result = $this->check(
            ':where(x) { }',
            'x { --frontend-edit-removed: var(--c-accent); }',
        );

        $this->assertSame(['--frontend-edit-removed'], $result['stale']);
    }

    /**
     * A custom property naming something undeclared fails the way custom
     * properties always fail: invalid at computed value time, and the page
     * renders anyway.
     */
    #[Test]
    public function aMappingNamingAThemeTokenTheThemeDoesNotDeclareIsReported(): void
    {
        $result = $this->check(
            ':where(x) { --frontend-edit-color-accent: #0a7bd4; }',
            'x { --frontend-edit-color-accent: var(--c-typo); }',
        );

        $this->assertSame(['--c-typo' => '--frontend-edit-color-accent'], $result['unknownThemeTokens']);
    }

    /**
     * Both stylesheets document the override mechanism by showing it, so a
     * comment contains a declaration that is not one.
     */
    #[Test]
    public function aDeclarationInsideACommentIsNotADeclaration(): void
    {
        $result = $this->check(
            ':where(x) {
                /* A site overrides it like this:
                 * --frontend-edit-color-accent: #b8003c;
                 */
                --frontend-edit-radius: 0.25rem;
            }',
            'x { --frontend-edit-radius: var(--space-1); }',
        );

        $this->assertSame(['--frontend-edit-radius'], array_keys($result['tokens']));
    }

    /**
     * The dark scheme block restates tokens the default block already declared.
     * What decides how a token is wired is the **first** declaration, so a
     * scheme override must not read as a second source.
     */
    #[Test]
    public function aDarkSchemeOverrideIsNotASecondSource(): void
    {
        $result = $this->check(
            ':where(x) { --frontend-edit-color-accent: #0a7bd4; }
             @media (prefers-color-scheme: dark) {
                 :where(x) { --frontend-edit-color-accent: #4c9ce4; }
             }',
            'x { --frontend-edit-color-accent: var(--c-accent); }',
        );

        $this->assertSame([], $result['unwired']);
        $this->assertSame(['--frontend-edit-color-accent' => '#0a7bd4'], $result['tokens']);
    }

    /**
     * A browser treats a cycle as invalid at computed value time. This must
     * report it rather than following it forever.
     */
    #[Test]
    public function aTokenThatReachesItselfIsUnwiredRatherThanEndless(): void
    {
        $result = $this->check(
            ':where(x) {
                --frontend-edit-a: var(--frontend-edit-b);
                --frontend-edit-b: var(--frontend-edit-a);
            }',
            'x { }',
        );

        $this->assertSame(['--frontend-edit-a', '--frontend-edit-b'], $result['unwired']);
    }

    /**
     * @param array<string, string> $themeCss
     * @return array{
     *     tokens: array<string, string>,
     *     undeclared: list<string>,
     *     unwired: list<string>,
     *     literal: array<string, string>,
     *     stale: list<string>,
     *     unknownThemeTokens: array<string, string>
     * }
     */
    private function check(string $surfaceCss, string $mappingCss, array $themeCss = self::THEME): array
    {
        return (new \DesignTokenWiringChecker())->check($surfaceCss, $mappingCss, $themeCss);
    }
}
