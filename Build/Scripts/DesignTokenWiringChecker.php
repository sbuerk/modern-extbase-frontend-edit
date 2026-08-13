<?php

declare(strict_types=1);

/**
 * Decides, for every design token the editing surface declares, whether its
 * value comes from one place or from two.
 *
 * The algorithm only; "checkDesignTokenWiring.php" adds the file reading and the
 * reporting. Split for the reason the other checkers here are split: the
 * traversal is uninteresting and the rules are not.
 *
 * ## What the rules are
 *
 * The extension ships a stylesheet that declares every "--frontend-edit-*" token
 * with a default, so that a page which themes nothing still renders a coherent
 * surface. The development site package then declares the same tokens in terms
 * of its own scale, which is what makes the two designs one design rather than
 * two that happen to agree.
 *
 * A token is **wired** when it has one source. Three shapes qualify:
 *
 * - **mapped** — the site package declares it as "var(--theme-token)".
 * - **derived** — the surface computes it from another token that is wired, so
 *   mapping it again would create the second copy this check exists to find.
 * - **inherited** — its default is "inherit", which takes the host page's value
 *   and is stronger than any mapping could be.
 *
 * Anything else is a second copy of a value, and this class says so.
 *
 * ## Why a literal in the mapping file is a finding rather than a mapping
 *
 * "--frontend-edit-space-xs: 0.25rem" in the site package looks like wiring and
 * is the exact failure being guarded against: it agrees with the theme today,
 * nothing connects them, and the day "--space-1" changes the two disagree in
 * silence. A mapping has to name a theme token.
 *
 * ## Why comments are stripped first
 *
 * Both stylesheets document the override mechanism by showing it, so a comment
 * contains a declaration that is not one:
 *
 *     modern-extbase-frontend-edit-profile {
 *         --frontend-edit-color-accent: #b8003c;
 *     }
 *
 * Parsing that as source would read an example as the definition. Stripping
 * comments before anything else is also what lets the rest of this class be a
 * handful of regular expressions rather than a CSS parser: nothing outside a
 * comment in these files is ambiguous.
 */
final class DesignTokenWiringChecker
{
    /**
     * The token prefix the editing surface owns. Anything else a value mentions
     * is a theme token and has to exist in the theme.
     */
    private const SURFACE_PREFIX = '--frontend-edit-';

    /**
     * @param string $surfaceCss The extension's shipped stylesheet, which declares the defaults.
     * @param string $mappingCss The site package's file that maps them onto the theme.
     * @param array<string, string> $themeCss Every other theme stylesheet, keyed by a readable name.
     * @return array{
     *     tokens: array<string, string>,
     *     undeclared: list<string>,
     *     unwired: list<string>,
     *     literal: array<string, string>,
     *     stale: list<string>,
     *     unknownThemeTokens: array<string, string>
     * }
     */
    public function check(string $surfaceCss, string $mappingCss, array $themeCss): array
    {
        $declared = $this->declarations($surfaceCss);
        $surfaceTokens = $this->onlySurfaceTokens($declared);
        $mapped = $this->onlySurfaceTokens($this->declarations($mappingCss));

        // Used with "var()" and never declared. The two outline tokens lived
        // this way for six pull requests, visible only as a fallback inside the
        // "var()" that read them, so a site could not find them by reading the
        // block that lists every token and the fallback colour was a second copy
        // of the accent that did not follow it into the dark scheme.
        $undeclared = [];
        foreach ($this->referencedTokens($surfaceCss) as $token) {
            if (str_starts_with($token, self::SURFACE_PREFIX) && !isset($surfaceTokens[$token])) {
                $undeclared[] = $token;
            }
        }
        sort($undeclared);

        // A mapping for a token the surface no longer has. Harmless to a
        // browser, which is exactly why nothing else would ever report it.
        $stale = [];
        foreach (array_keys($mapped) as $token) {
            if (!isset($surfaceTokens[$token])) {
                $stale[] = $token;
            }
        }
        sort($stale);

        $literal = [];
        foreach ($mapped as $token => $value) {
            if (isset($surfaceTokens[$token]) && !$this->namesAThemeToken($value)) {
                $literal[$token] = $value;
            }
        }
        ksort($literal);

        // A token mapped to a literal is unwired as well, by construction. It is
        // reported under the more specific heading only, because two findings
        // naming one token read as two problems.
        $unwired = [];
        foreach (array_keys($surfaceTokens) as $token) {
            if (!isset($literal[$token]) && !$this->isWired($token, $surfaceTokens, $mapped, [])) {
                $unwired[] = $token;
            }
        }
        sort($unwired);

        return [
            'tokens' => $surfaceTokens,
            'undeclared' => $undeclared,
            'unwired' => $unwired,
            'literal' => $literal,
            'stale' => $stale,
            'unknownThemeTokens' => $this->unknownThemeTokens($mapped, $themeCss),
        ];
    }

    /**
     * Whether a mapping actually points at the theme rather than repeating a
     * value. A mapping that only mentions surface tokens is not a mapping — it
     * is the surface talking to itself in the site package's file.
     */
    private function namesAThemeToken(string $value): bool
    {
        foreach ($this->referencedTokens($value) as $token) {
            if (!str_starts_with($token, self::SURFACE_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $surfaceTokens
     * @param array<string, string> $mapped
     * @param list<string> $seen Guards a token that reaches itself; a browser
     *                           treats such a cycle as invalid at computed value
     *                           time, and this must not hang on it.
     */
    private function isWired(string $token, array $surfaceTokens, array $mapped, array $seen): bool
    {
        if (in_array($token, $seen, true)) {
            return false;
        }
        if (isset($mapped[$token])) {
            return $this->namesAThemeToken($mapped[$token]);
        }

        $default = trim($surfaceTokens[$token] ?? '');
        if ($default === 'inherit') {
            return true;
        }

        // Derived: every token the default mentions is a surface token, and each
        // of those is itself wired. A default that mentions nothing at all is a
        // literal, which is the whole point of the check.
        $references = $this->referencedTokens($default);
        if ($references === []) {
            return false;
        }

        $seen[] = $token;
        foreach ($references as $reference) {
            if (!str_starts_with($reference, self::SURFACE_PREFIX)) {
                return false;
            }
            if (!$this->isWired($reference, $surfaceTokens, $mapped, $seen)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Theme tokens a mapping names that no theme stylesheet declares.
     *
     * A typo here fails the way custom properties always fail — silently. The
     * property becomes invalid at computed value time, the surface falls back to
     * an inherited value or to nothing, and the page renders.
     *
     * @param array<string, string> $mapped
     * @param array<string, string> $themeCss
     * @return array<string, string> Theme token to the surface token that names it.
     */
    private function unknownThemeTokens(array $mapped, array $themeCss): array
    {
        $available = [];
        foreach ($themeCss as $css) {
            foreach (array_keys($this->declarations($css)) as $token) {
                $available[$token] = true;
            }
        }

        $unknown = [];
        foreach ($mapped as $surfaceToken => $value) {
            foreach ($this->referencedTokens($value) as $token) {
                if (!str_starts_with($token, self::SURFACE_PREFIX) && !isset($available[$token])) {
                    $unknown[$token] = $surfaceToken;
                }
            }
        }
        ksort($unknown);

        return $unknown;
    }

    /**
     * Every custom property declaration, first one wins.
     *
     * First rather than last because the default block is written before the
     * "prefers-color-scheme" and "prefers-reduced-motion" blocks that restate
     * some of it, and the default is what decides how a token is wired — a dark
     * scheme override of an already wired token is not a second source.
     *
     * @return array<string, string>
     */
    private function declarations(string $css): array
    {
        $css = $this->withoutComments($css);
        preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+);/', $css, $matches, PREG_SET_ORDER);

        $declarations = [];
        foreach ($matches as $match) {
            $declarations[$match[1]] ??= trim(preg_replace('/\s+/', ' ', $match[2]) ?? '');
        }

        return $declarations;
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function onlySurfaceTokens(array $declarations): array
    {
        $tokens = [];
        foreach ($declarations as $token => $value) {
            if (str_starts_with($token, self::SURFACE_PREFIX)) {
                $tokens[$token] = $value;
            }
        }
        ksort($tokens);

        return $tokens;
    }

    /**
     * @return list<string>
     */
    private function referencedTokens(string $css): array
    {
        preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $this->withoutComments($css), $matches);

        return array_values(array_unique($matches[1]));
    }

    private function withoutComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    }
}
