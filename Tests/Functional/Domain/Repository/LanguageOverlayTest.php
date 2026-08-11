<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Model\Profile;
use SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\ProfileRepository;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractProfileTestCase;

/**
 * Language overlay on the display repository.
 *
 * The display repository carries no language handling of its own — and that is
 * the assertion: it must inherit the language aspect of the request, so a
 * plugin on a translated page shows the translated record without the
 * repository knowing anything about languages.
 *
 * The fixture is asymmetric on purpose. Profile 22 has no translation and the
 * DE site language uses `fallbackType: 'strict'`, so the DE expectation cannot
 * be produced by a query that never applied a language context at all — which
 * is exactly how such a test passes for the wrong reason. Replacing
 * `executeInFrontendContext()` with a plain call of the closure makes the DE
 * case fail while EN keeps passing, because EN *is* the default language.
 */
final class LanguageOverlayTest extends AbstractProfileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/Database/TranslatedProfiles.csv');
    }

    /**
     * @return \Generator<string, array{languageId: int, expectedShortnames: list<string>}>
     */
    public static function siteLanguages(): \Generator
    {
        yield '0 EN -> both default language records' => [
            'languageId' => 0,
            'expectedShortnames' => ['translated', 'untranslated'],
        ];
        yield '1 DE -> the translated record only' => [
            'languageId' => 1,
            'expectedShortnames' => ['translated-de'],
        ];
    }

    /**
     * @param list<string> $expectedShortnames
     */
    #[DataProvider('siteLanguages')]
    #[Test]
    public function displayRepositoryReturnsTheRecordsOfTheLanguageContextItRunsIn(
        int $languageId,
        array $expectedShortnames,
    ): void {
        $shortnames = [];
        $this->executeInFrontendContext(function () use (&$shortnames): void {
            foreach ($this->get(ProfileRepository::class)->findAll() as $profile) {
                $shortnames[] = $profile->getShortname();
            }
            sort($shortnames);
        }, $languageId);

        $this->assertSame($expectedShortnames, $shortnames);
    }

    /**
     * An overlaid record keeps the uid of the record it is a translation *of*.
     *
     * That is not a detail: a uid taken from a rendered listing and handed back
     * to an edit request identifies the default language record, never the
     * translation. The localized uid is available separately, through
     * `_localizedUid`, and is what the translated row itself is numbered with.
     */
    #[Test]
    public function overlaidProfileCarriesTheUidOfTheDefaultLanguageRecord(): void
    {
        $uid = null;
        $localizedUid = null;
        $shortname = null;
        $this->executeInFrontendContext(function () use (&$uid, &$localizedUid, &$shortname): void {
            $profile = $this->get(ProfileRepository::class)->findByUid(20);
            $this->assertInstanceOf(Profile::class, $profile);

            $uid = $profile->getUid();
            $localizedUid = $profile->_getProperty('_localizedUid');
            $shortname = $profile->getShortname();
        }, 1);

        $this->assertSame(20, $uid);
        $this->assertSame(21, $localizedUid);
        $this->assertSame('translated-de', $shortname);
    }
}
