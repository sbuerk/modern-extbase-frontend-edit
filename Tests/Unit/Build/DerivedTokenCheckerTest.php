<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The rule of `-s checkDerivedTokens`, on stylesheets small enough to reason
 * about.
 *
 * The gate itself is pointed at the real stylesheets, where it currently finds
 * nothing — which is the desired state and a poor way to learn what it does. A
 * checker that reports nothing is indistinguishable from a checker that cannot
 * report anything, and the only evidence otherwise was a maintainer temporarily
 * breaking the theme and watching it go red. That evidence disappears with the
 * terminal it was printed in.
 *
 * What matters most here is the **negative** cases. The rule is deliberately
 * narrower than "any token can go stale", and the two shapes it must stay silent
 * about — a token declared below the scheme switch, and two contexts that are
 * both the document root — are exactly the shapes a later simplification would
 * remove. Each has a test whose name says why it is not a finding.
 *
 * The checker is not namespaced and is not part of the shipped autoload map — it
 * is build tooling. `composer.json` lists it under `autoload-dev.classmap`,
 * which is what makes it reachable from here and resolvable for PHPStan.
 *
 * @see Build/Scripts/DerivedTokenChecker.php
 */
final class DerivedTokenCheckerTest extends UnitTestCase
{
    /**
     * The defect the gate was written for: a token resolved on the root against
     * the light palette, inherited past the element where the scheme changes.
     */
    #[Test]
    public function aRootTokenReadingASwitchedTokenIsReported(): void
    {
        $result = $this->check([
            'theme.css' => <<<'CSS'
                :root {
                    --c-accent: #2563a8;
                    --focus-color: var(--c-accent);
                }

                body[data-color-scheme='dark'] {
                    --c-accent: #6ba4e0;
                }
                CSS,
        ]);

        $this->assertSame(
            [['token' => '--focus-color', 'reads' => '--c-accent', 'context' => "body[data-color-scheme='dark']"]],
            $result['stale'],
        );
    }

    #[Test]
    public function everyContextThatRedefinesTheSourceIsNamedSeparately(): void
    {
        $result = $this->check([
            'theme.css' => <<<'CSS'
                :root {
                    --c-accent: #2563a8;
                    --focus-color: var(--c-accent);
                }

                body[data-color-scheme='auto'] {
                    --c-accent: #6ba4e0;
                }

                body[data-color-scheme='dark'] {
                    --c-accent: #6ba4e0;
                }
                CSS,
        ]);

        $this->assertSame(
            ["body[data-color-scheme='auto']", "body[data-color-scheme='dark']"],
            array_column($result['stale'], 'context'),
        );
    }

    #[Test]
    public function aTokenRestatedInTheContextThatSwitchesItIsNotReported(): void
    {
        $result = $this->check([
            'theme.css' => <<<'CSS'
                :root {
                    --c-accent: #2563a8;
                    --focus-color: var(--c-accent);
                }

                body[data-color-scheme='dark'] {
                    --c-accent: #6ba4e0;
                    --focus-color: var(--c-accent);
                }
                CSS,
        ]);

        $this->assertSame([], $result['stale']);
    }

    /**
     * The shape of `_plugin.css`, and the false positive the rule is narrowed to
     * avoid: the surface element sits **below** the body that switches the
     * scheme, so it resolves against the value in force there. Widening the rule
     * to any declaring context would report this, and it is correct.
     */
    #[Test]
    public function aTokenDeclaredBelowTheSwitchIsNotReportedBecauseItResolvesCorrectly(): void
    {
        $result = $this->check([
            'theme.css' => <<<'CSS'
                :root {
                    --c-accent: #2563a8;
                }

                body[data-color-scheme='dark'] {
                    --c-accent: #6ba4e0;
                }

                modern-extbase-frontend-edit-profile {
                    --frontend-edit-color-accent: var(--c-accent);
                }
                CSS,
        ]);

        $this->assertSame([], $result['stale']);
        $this->assertSame([], $result['derived'], 'only root declared tokens are the subject of this rule');
    }

    /**
     * A scheme pinned on the root itself is the **same element** as the
     * defaults, so the cascade picks the dark declaration before substitution
     * happens. This is also why the extension's own stylesheet is clean.
     */
    #[Test]
    public function twoContextsThatBothMatchTheRootAreTheSameElement(): void
    {
        $result = $this->check([
            'theme.css' => <<<'CSS'
                :root {
                    --c-accent: #2563a8;
                    --focus-color: var(--c-accent);
                }

                html[data-theme='dark'] {
                    --c-accent: #6ba4e0;
                }
                CSS,
        ]);

        $this->assertSame([], $result['stale']);
    }

    /**
     * A media query changes when a declaration applies, never which element it
     * lands on — so the restatement below counts even though it is outside the
     * block that switches the source.
     */
    #[Test]
    public function aMediaQueryDoesNotMakeASecondContext(): void
    {
        $result = $this->check([
            'theme.css' => <<<'CSS'
                :root {
                    --c-accent: #2563a8;
                    --focus-color: var(--c-accent);
                }

                body[data-color-scheme='auto'] {
                    --focus-color: var(--c-accent);
                }

                @media (prefers-color-scheme: dark) {
                    body[data-color-scheme='auto'] {
                        --c-accent: #6ba4e0;
                    }
                }
                CSS,
        ]);

        $this->assertSame([], $result['stale']);
    }

    /**
     * Proves the block scanner does not stop at the first closing brace, which
     * is the failure a non-greedy regular expression would produce here — and it
     * would look exactly like a pass.
     */
    #[Test]
    public function scanningContinuesPastANestedAtRule(): void
    {
        $result = $this->check([
            'theme.css' => <<<'CSS'
                @media (min-width: 30em) {
                    .unrelated {
                        --spacing: 1rem;
                    }
                }

                :root {
                    --c-accent: #2563a8;
                    --focus-color: var(--c-accent);
                }

                body[data-color-scheme='dark'] {
                    --c-accent: #6ba4e0;
                }
                CSS,
        ]);

        $this->assertCount(1, $result['stale']);
    }

    #[Test]
    public function theStylesheetsAreReadAsOneCascade(): void
    {
        $result = $this->check([
            'variables.css' => ':root { --c-accent: #2563a8; --focus-color: var(--c-accent); }',
            'dark.css' => "body[data-color-scheme='dark'] { --c-accent: #6ba4e0; }",
        ]);

        $this->assertCount(
            1,
            $result['stale'],
            'a token declared in one file and switched in another is one cascade, not two',
        );
    }

    #[Test]
    public function aDeclarationInsideACommentIsNotASource(): void
    {
        $result = $this->check([
            'theme.css' => <<<'CSS'
                :root {
                    --c-accent: #2563a8;
                    --focus-color: var(--c-accent);
                }

                /*
                 * body[data-color-scheme='dark'] {
                 *     --c-accent: #6ba4e0;
                 * }
                 */
                CSS,
        ]);

        $this->assertSame([], $result['stale']);
    }

    #[Test]
    public function onlyTokensThatReadAnotherTokenAreCounted(): void
    {
        $result = $this->check([
            'theme.css' => ':root { --c-accent: #2563a8; --focus-width: 2px; --focus-color: var(--c-accent); }',
        ]);

        $this->assertSame(['--focus-color'], array_keys($result['derived']));
    }

    /**
     * @param array<string, string> $stylesheets
     * @return array{derived: array<string, string>, stale: list<array{token: string, reads: string, context: string}>}
     */
    private function check(array $stylesheets): array
    {
        return (new \DerivedTokenChecker())->check($stylesheets);
    }
}
