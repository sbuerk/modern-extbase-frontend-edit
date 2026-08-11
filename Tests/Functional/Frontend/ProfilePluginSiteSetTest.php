<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\Test;

/**
 * Both plugins on a site that is configured through **site sets** instead of a
 * `sys_template` record.
 *
 * The extension ships both flavours, and they are not the same code path:
 *
 * - the site set flavour has no `sys_template` record at all, so
 *   `SysTemplateTreeBuilder::createSiteTemplateInclude()` assembles the
 *   TypoScript, and the plugin rendering definition is included unconditionally
 *   rather than behind a static include, and
 * - the settings come from the `settings` of the site configuration, mapped
 *   onto `plugin.tx_modernextbasefrontendedit` by
 *   `Configuration/Sets/Profiles/setup.typoscript`, rather than from TypoScript
 *   constants.
 *
 * The behaviour of the plugins themselves is covered by
 * {@see ProfileListPluginTest} and {@see ProfileShowPluginTest}; what is
 * asserted here is that this flavour resolves the same three settings — storage
 * page, detail page, edit page — and renders the same output.
 */
final class ProfilePluginSiteSetTest extends AbstractProfilePluginTestCase
{
    protected function setUpProfilePluginRendering(): void
    {
        $this->setUpSiteSetFlavour();
    }

    /**
     * The storage page setting: the two visible profiles of page 1 are listed,
     * and the profile on the page outside it is not.
     */
    #[Test]
    public function listPluginRendersTheProfilesOfTheStoragePageOfTheSiteSettings(): void
    {
        $response = $this->renderListPlugin();

        $this->assertSame(200, $response->getStatusCode());

        $body = (string)$response->getBody();
        $renderedNames = array_keys($this->profileCards($body));
        sort($renderedNames);

        $this->assertSame(['Ada Lovelace', 'Radia Perlman'], $renderedNames);
        $this->assertStringContainsString('<h2>Profiles</h2>', $body);
        $this->assertStringNotContainsString('Anita Borg', $body);
    }

    /**
     * The detail page setting, which only the list plugin reads.
     */
    #[Test]
    public function listPluginLinksToTheDetailPageOfTheSiteSettings(): void
    {
        $cards = $this->profileCards((string)$this->renderListPlugin()->getBody());

        $this->assertStringContainsString('href="/profiles?', $cards['Ada Lovelace']);
        $this->assertStringContainsString('View profile', $cards['Ada Lovelace']);
    }

    /**
     * The edit page setting, together with the ownership flag.
     */
    #[Test]
    public function editLinkOfAnOwnedProfilePointsAtTheEditPageOfTheSiteSettings(): void
    {
        $cards = $this->profileCards(
            (string)$this->renderListPlugin(self::OWNER_FRONTEND_USER_ID)->getBody(),
        );

        $this->assertStringContainsString('href="/edit-profile"', $cards['Ada Lovelace']);
        $this->assertStringContainsString('Edit profile', $cards['Ada Lovelace']);
        $this->assertStringNotContainsString('/edit-profile', $cards['Radia Perlman']);
    }

    #[Test]
    public function showPluginRendersTheProfileWithItsChildren(): void
    {
        $response = $this->renderShowPlugin(1);

        $this->assertSame(200, $response->getStatusCode());

        $body = (string)$response->getBody();
        $this->assertStringContainsString('Ada Lovelace', $body);
        $this->assertStringContainsString('Addresses', $body);
        $this->assertStringContainsString('E-mail addresses', $body);
        $this->assertStringContainsString('Difference Engine Road 1', $body);
        $this->assertStringContainsString('first@example.org', $body);
    }

    /**
     * The storage page setting again, this time on the guard of the show
     * action — and at the same time the proof that the two `mvc` switches of
     * the classic TypoScript reach a site set based installation as well: they
     * are not repeated in the set, they come from the `siteSets` scope that
     * `ext_localconf.php` writes.
     */
    #[Test]
    public function showPluginReturnsPageNotFoundForAProfileOutsideTheStoragePage(): void
    {
        $response = $this->renderShowPlugin(5);

        $this->assertSame(404, $response->getStatusCode());
    }
}
