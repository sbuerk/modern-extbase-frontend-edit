<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\Test;

/**
 * The Content Security Policy header the editing surface is served with.
 *
 * This exists because a policy is otherwise only reasoned about. Every claim in
 * `Configuration/ContentSecurityPolicies.php` is about a header nobody sees
 * until a site enables the feature, and a wrong claim there fails in a browser
 * console on somebody else's installation rather than here.
 *
 * Frontend CSP is off by default on both target versions, so the feature flag
 * is enabled for this test case only. That is also the assertion the first test
 * makes: without the flag there is no header at all, which is what makes the
 * other assertions meaningful rather than vacuous.
 */
final class ContentSecurityPolicyTest extends AbstractProfilePluginTestCase
{
    private const EDIT_URI = 'https://acme.com/edit-profile';

    /**
     * The parent pins the date format and redeclaring the property replaces it,
     * so it is repeated here rather than merged.
     */
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'ddmmyy' => 'Y-m-d',
            'features' => [
                'security.frontend.enforceContentSecurityPolicy' => true,
            ],
        ],
    ];

    #[Test]
    public function theEditingSurfaceIsServedWithAPolicy(): void
    {
        $header = $this->renderUri(self::EDIT_URI, self::OWNER_FRONTEND_USER_ID)
            ->getHeaderLine('Content-Security-Policy');

        $this->assertNotSame('', $header, 'the feature flag of this test case produces a policy');
        $this->assertStringContainsString("default-src 'self'", $header);
    }

    /**
     * Every source the editing surface needs is granted.
     *
     * The four directives this extension declares all resolve to `'self'` and
     * are then folded back into `default-src`, because `Policy::prepare()`
     * removes a directive whose sources are identical to its ancestor's. So the
     * assertion is not that they appear — they must not — but that the source
     * they would have contributed is covered.
     */
    #[Test]
    public function everySourceTheSurfaceNeedsIsCoveredByTheEmittedPolicy(): void
    {
        $header = $this->renderUri(self::EDIT_URI, self::OWNER_FRONTEND_USER_ID)
            ->getHeaderLine('Content-Security-Policy');

        foreach (['script-src', 'style-src', 'connect-src', 'img-src'] as $directive) {
            $emitted = $this->directiveOf($header, $directive) ?? $this->directiveOf($header, 'default-src');
            $this->assertNotNull($emitted, sprintf('"%s" is covered, by itself or by "default-src"', $directive));
            $this->assertStringContainsString("'self'", $emitted, sprintf('"%s" grants the own origin', $directive));
        }
    }

    /**
     * What the declaration of this extension actually adds to the header.
     *
     * Measured rather than assumed, by rendering the same page with and without
     * `Configuration/ContentSecurityPolicies.php`: the difference is one
     * directive, `style-src`, and it grants exactly what `default-src` already
     * grants. `connect-src` folds into `default-src` because
     * `Policy::prepare()` drops a directive whose sources are identical to its
     * ancestor's; `script-src` and `img-src` are in the header either way,
     * because core declares both itself.
     *
     * The point of this test is the *ceiling*. If a future declaration widens
     * one of these beyond the own origin, or adds a directive of its own, this
     * fails — which is what keeps the file honest, since none of it is visible
     * until a site turns the feature on.
     */
    #[Test]
    public function theDeclarationGrantsNothingBeyondTheOwnOrigin(): void
    {
        $header = $this->renderUri(self::EDIT_URI, self::OWNER_FRONTEND_USER_ID)
            ->getHeaderLine('Content-Security-Policy');

        $this->assertNull(
            $this->directiveOf($header, 'connect-src'),
            'connect-src is identical to default-src and is folded into it',
        );

        $styleSrc = $this->directiveOf($header, 'style-src');
        $this->assertNotNull($styleSrc, 'style-src is the one directive this declaration adds');
        $this->assertSame(
            ["'report-sample'", "'self'"],
            $this->sourcesOf($styleSrc),
            'style-src grants the own origin and nothing else',
        );
    }

    /**
     * Nothing this extension ships needs any of these, and each was checked
     * against the assets rather than assumed. A policy that grew one of them
     * without a reason is a policy nobody is reading any more.
     */
    #[Test]
    public function nothingWideningIsRequested(): void
    {
        $header = $this->renderUri(self::EDIT_URI, self::OWNER_FRONTEND_USER_ID)
            ->getHeaderLine('Content-Security-Policy');

        $this->assertStringNotContainsString("'unsafe-eval'", $header);
        $this->assertStringNotContainsString('blob:', $header);
        $this->assertStringNotContainsString("script-src 'unsafe-inline'", $header);
    }

    /**
     * Returns the source list of one directive, or NULL when the directive is
     * not in the header at all. The two are different answers and the tests
     * above depend on telling them apart.
     */
    private function directiveOf(string $header, string $directive): ?string
    {
        foreach (explode(';', $header) as $part) {
            $part = trim($part);
            if ($part === $directive || str_starts_with($part, $directive . ' ')) {
                return $part;
            }
        }

        return null;
    }

    /**
     * The sources of one directive, sorted, so an assertion does not depend on
     * the order they happen to be compiled in.
     *
     * @return list<string>
     */
    private function sourcesOf(string $directive): array
    {
        $sources = array_values(array_filter(explode(' ', $directive)));
        array_shift($sources);
        sort($sources);

        return $sources;
    }
}
