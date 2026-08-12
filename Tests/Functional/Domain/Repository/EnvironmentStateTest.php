<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\ProfileRepository;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractProfileTestCase;
use TYPO3\CMS\Core\Context\LanguageAspect;

/**
 * The two properties of the built environment that nothing else covers.
 *
 * A repository query outside of a request — in a command, a scheduler task or,
 * as here, a test — has no language context unless one is built.
 * `fgtclb/environment-state-manager` builds it, and this repository uses it
 * through {@see AbstractProfileTestCase::executeInFrontendContext()}.
 *
 * That the environment is *applied* is asserted by
 * {@see LanguageOverlayTest}, which is where the per language expectations
 * live. Asserted here is what that test cannot see:
 *
 * 1. A query that pins its own language aspect is **not** overruled by the
 *    environment. Without this, a caller who deliberately asks for the default
 *    language would silently get the environment's language instead, and every
 *    per language test would still pass.
 * 2. The previous environment is **restored** afterwards. A builder that
 *    applies a context and leaks it makes the next test in the same process
 *    pass or fail depending on the order tests happen to run in, which is the
 *    hardest kind of failure to attribute.
 *
 * The fixture is the same asymmetric one `LanguageOverlayTest` uses: profile 22
 * has no translation and the DE language is `fallbackType: 'strict'`, so a DE
 * context returns exactly one record while the default language returns two.
 * That asymmetry is what makes the first assertion below meaningful — if the
 * pinned aspect were ignored, the result would be the single DE record.
 */
final class EnvironmentStateTest extends AbstractProfileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/TranslatedProfiles.csv');
    }

    #[Test]
    public function queryWithAPinnedLanguageAspectIsUnaffectedByTheEnvironment(): void
    {
        $shortnames = [];
        $this->executeInFrontendContext(function () use (&$shortnames): void {
            $repository = $this->get(ProfileRepository::class);
            $query = $repository->createQuery();
            $query->getQuerySettings()->setLanguageAspect(
                new LanguageAspect(0, 0, LanguageAspect::OVERLAYS_OFF),
            );

            foreach ($query->execute() as $profile) {
                $this->assertInstanceOf(Profile::class, $profile);
                $shortnames[] = $profile->getShortname();
            }
        }, languageId: 1);

        sort($shortnames);
        $this->assertSame(['translated', 'untranslated'], $shortnames);
    }

    #[Test]
    public function environmentIsRestoredAfterExecute(): void
    {
        $before = $GLOBALS['TYPO3_REQUEST'] ?? null;

        $this->executeInFrontendContext(function (): void {
            $this->assertNotNull($GLOBALS['TYPO3_REQUEST'] ?? null);
        }, languageId: 1);

        $this->assertSame($before, $GLOBALS['TYPO3_REQUEST'] ?? null);
    }
}
