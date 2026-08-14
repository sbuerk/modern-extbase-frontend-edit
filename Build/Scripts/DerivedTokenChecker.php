<?php

declare(strict_types=1);

/**
 * Finds custom properties that cannot follow a colour scheme, because of where
 * they were declared rather than because of what they say.
 *
 * The algorithm only; "checkDerivedTokens.php" adds the file reading and the
 * reporting, for the reason the other checkers here are split: the traversal is
 * uninteresting and the rule is not.
 *
 * ## The defect
 *
 * A custom property is substituted **at computed value time on the element that
 * declares it**. So this:
 *
 *     :root {
 *         --c-accent: #2563a8;
 *         --focus-color: var(--c-accent);
 *     }
 *
 *     body[data-color-scheme='dark'] {
 *         --c-accent: #6ba4e0;
 *     }
 *
 * resolves "--focus-color" to "#2563a8" **on :root**, and what inherits down to
 * "body" is that literal colour rather than the reference. Redefining
 * "--c-accent" on "body" changes everything that reads it directly, and changes
 * nothing that read it one element higher. The focus ring of every control on a
 * dark page therefore stayed the light accent — 2.80:1 against the page, under
 * the 3:1 that WCAG 1.4.11 asks of a focus indicator.
 *
 * Nothing else in this repository can see it. A browser cannot: the declaration
 * is valid and resolves to a real colour. The image suites cannot: a ring in the
 * wrong blue looks entirely deliberate in a screenshot, and two of them carried
 * it for several pull requests. The contrast tests measure specific elements, so
 * they can only find the instances somebody thought to measure.
 *
 * ## The rule, and why it is narrow on purpose
 *
 * A finding needs three things at once:
 *
 * 1. a token **T** declared on the **document root** — ":root" or "html" — whose
 *    value reads another custom property **S**,
 * 2. **S** redeclared in some context **B** that is *not* the root,
 * 3. **T** not redeclared in **B**.
 *
 * The root is the only selector that is guaranteed to be an ancestor of every
 * other element, which is what makes this decidable from selector text alone.
 * For any other pair the answer depends on where the two elements sit in the
 * document — a token declared on "modern-extbase-frontend-edit-profile" that
 * reads a token switched on "body" is perfectly safe, because the surface is
 * *below* body and resolves against the value in force there. Reporting that
 * would be a false positive, and a gate that cries wolf is a gate somebody
 * deletes.
 *
 * So this deliberately answers a smaller question than "can any token go stale":
 * it answers "can a **root declared** token go stale", which is the whole of the
 * exposure in a theme that switches its scheme on "body", and which is where the
 * one real instance was. A theme that switched schemes somewhere else would need
 * this widened, and would need real ancestor analysis to do it.
 *
 * Two contexts that both match the root are the **same element**, so a scheme
 * pinned with "html[data-theme='dark']" against defaults on ":root" is not a
 * finding: the cascade picks the dark declaration before substitution happens.
 * That is the same reason the extension's own stylesheet is clean — it declares
 * its defaults and its dark overrides on one selector.
 */
final class DerivedTokenChecker
{
    /**
     * @param array<string, string> $stylesheets File name to CSS. Pass every
     *        stylesheet of one document: they form a single cascade, and a token
     *        declared in one file is redeclared in another.
     * @return array{
     *     derived: array<string, string>,
     *     stale: list<array{token: string, reads: string, context: string}>
     * }
     */
    public function check(array $stylesheets): array
    {
        /** @var array<string, array<string, string>> $declarations context => token => value */
        $declarations = [];
        foreach ($stylesheets as $css) {
            foreach ($this->rules($this->withoutComments($css)) as $rule) {
                foreach ($rule['declarations'] as $token => $value) {
                    // First declaration wins, mirroring DesignTokenWiringChecker:
                    // what decides whether a token is exposed is where it is
                    // *first* declared, and a later restatement in the same
                    // context is not a second context.
                    $declarations[$rule['selector']][$token] ??= $value;
                }
            }
        }

        $rootContexts = [];
        $otherContexts = [];
        foreach (array_keys($declarations) as $context) {
            if ($this->isRoot($context)) {
                $rootContexts[] = $context;
            } else {
                $otherContexts[] = $context;
            }
        }

        $derived = [];
        $stale = [];
        foreach ($rootContexts as $context) {
            foreach ($declarations[$context] as $token => $value) {
                $reads = $this->referencedTokens($value);
                if ($reads === []) {
                    continue;
                }
                $derived[$token] = $value;

                foreach ($reads as $source) {
                    foreach ($otherContexts as $other) {
                        if (!isset($declarations[$other][$source])) {
                            continue;
                        }
                        if (isset($declarations[$other][$token])) {
                            continue;
                        }
                        $stale[] = [
                            'token' => $token,
                            'reads' => $source,
                            'context' => $other,
                        ];
                    }
                }
            }
        }

        ksort($derived);
        usort($stale, static fn(array $a, array $b): int
            => [$a['token'], $a['context']] <=> [$b['token'], $b['context']]);

        return ['derived' => $derived, 'stale' => $stale];
    }

    /**
     * Whether a selector matches the document root, and therefore an ancestor of
     * everything else.
     *
     * A selector list counts when **any** of its parts does: the declarations
     * then land on the root as well, which is all this needs to know.
     */
    private function isRoot(string $selector): bool
    {
        foreach (explode(',', $selector) as $part) {
            if (preg_match('/^\s*(:root|html)\b/', $part) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every style rule, at-rule bodies included and their conditions discarded.
     *
     * A media query does not change which *element* a declaration lands on, and
     * this is a question about elements — so "body[…] { }" inside
     * "@media (prefers-color-scheme: dark)" is the same context as one outside
     * it, and merging the two is correct rather than a simplification.
     *
     * @return list<array{selector: string, declarations: array<string, string>}>
     */
    private function rules(string $css): array
    {
        $rules = [];
        foreach ($this->blocks($css) as $block) {
            if (str_starts_with($block['prelude'], '@')) {
                foreach ($this->rules($block['body']) as $nested) {
                    $rules[] = $nested;
                }
                continue;
            }

            $declarations = $this->declarations($block['body']);
            if ($declarations !== []) {
                $rules[] = [
                    'selector' => $this->normalise($block['prelude']),
                    'declarations' => $declarations,
                ];
            }
        }

        return $rules;
    }

    /**
     * Balanced top level blocks, scanned rather than matched.
     *
     * A regular expression cannot do this: the body of an at-rule contains
     * braces, and a non-greedy match stops at the first closing one — which
     * would silently read a single nested rule and report nothing about the
     * rest, a failure that looks exactly like a pass.
     *
     * @return list<array{prelude: string, body: string}>
     */
    private function blocks(string $css): array
    {
        $blocks = [];
        $depth = 0;
        $start = 0;
        $preludeStart = 0;
        $prelude = '';
        $length = strlen($css);

        for ($position = 0; $position < $length; $position++) {
            if ($css[$position] === '{') {
                if ($depth === 0) {
                    $prelude = trim(substr($css, $preludeStart, $position - $preludeStart));
                    $start = $position + 1;
                }
                $depth++;
                continue;
            }
            if ($css[$position] !== '}') {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                $blocks[] = ['prelude' => $prelude, 'body' => substr($css, $start, $position - $start)];
                $preludeStart = $position + 1;
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, string>
     */
    private function declarations(string $body): array
    {
        // Only the top level of the block: a declaration inside a nested rule
        // belongs to that rule's selector and is picked up when it is visited.
        $body = preg_replace('/\{[^{}]*\}/', '', $body) ?? $body;
        preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+);/', $body, $matches, PREG_SET_ORDER);

        $declarations = [];
        foreach ($matches as $match) {
            $declarations[$match[1]] ??= trim($match[2]);
        }

        return $declarations;
    }

    /**
     * @return list<string>
     */
    private function referencedTokens(string $value): array
    {
        preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $value, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function normalise(string $selector): string
    {
        return trim(preg_replace('/\s+/', ' ', $selector) ?? $selector);
    }

    private function withoutComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    }
}
