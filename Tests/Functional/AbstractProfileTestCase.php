<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional;

use FGTCLB\EnvironmentStateManager\StateBuildContext;
use FGTCLB\EnvironmentStateManager\StateManagerInterface;
use SBUERK\TYPO3\Testing\SiteHandling\SiteBasedTestTrait;
use TYPO3\CMS\Core\Http\ApplicationType;

/**
 * Shared setup of the functional tests covering the profile domain.
 *
 * It is an intermediate base class, so the chain still ends at the
 * `FunctionalTestCase` of `sbuerk/typo3-site-based-test-trait` through
 * {@see AbstractFunctionalTestCase} — see the "Site based tests" page of the
 * developer documentation.
 *
 * ## Why every repository call runs inside a frontend environment
 *
 * The behaviour under test only exists in the frontend.
 * `Typo3DbQueryParser::getVisibilityConstraintStatement()` looks at
 * `$GLOBALS['TYPO3_REQUEST']` and dispatches to
 * `getFrontendConstraintStatement()` only for a frontend request; without one
 * it takes the **backend** branch, where `setEnableFieldsToBeIgnored()` is not
 * even read and `BackendUtility::BEenableFields()` decides visibility. A test
 * asserting the enable field split outside of a frontend context would
 * therefore assert something else entirely, and the difference between the two
 * edit query settings — the whole point of {@see \SBUERK\ModernExtbaseFrontendEdit\Domain\Repository\Edit\AbstractEditRepository::createEditQuery()}
 * — would be invisible.
 *
 * `fgtclb/environment-state-manager` builds that environment, applies it and
 * restores the previous one afterwards, including when the closure throws.
 *
 * ## Why the storage page id is configured in TypoScript
 *
 * `QueryFactory::create()` reads `persistence.storagePid` from the framework
 * configuration and falls back to `0`, so without the `config.tx_extbase`
 * setting below every query would carry `pid = 0` and find none of the fixture
 * records, which all live on the root page. Setting it rather than switching
 * `respectStoragePage` off in the test keeps the production repositories
 * exactly as a plugin uses them.
 */
abstract class AbstractProfileTestCase extends AbstractFunctionalTestCase
{
    use SiteBasedTestTrait;

    protected const LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
        'DE' => ['id' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF8'],
    ];

    protected const PROFILE_TABLE = 'tx_modernextbasefrontendedit_domain_model_profile';
    protected const ADDRESS_TABLE = 'tx_modernextbasefrontendedit_domain_model_address';
    protected const EMAIL_TABLE = 'tx_modernextbasefrontendedit_domain_model_email';

    /**
     * The page all fixture records live on, and the storage page id the
     * repositories are configured with.
     */
    protected const STORAGE_PAGE_ID = 1;

    protected array $testExtensionsToLoad = [
        'sbuerk/modern-extbase-frontend-edit',
        'fgtclb/environment-state-manager',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/Database/ProfileSite.csv');
        $this->writeSiteConfiguration(
            'acme',
            $this->buildSiteConfiguration(
                rootPageId: self::STORAGE_PAGE_ID,
                base: 'https://acme.com/',
                websiteTitle: 'ACME',
            ),
            [
                $this->buildDefaultLanguageConfiguration(
                    identifier: 'EN',
                    base: 'https://acme.com/',
                ),
                $this->buildLanguageConfiguration(
                    identifier: 'DE',
                    base: 'https://acme.com/de/',
                    fallbackIdentifiers: ['EN'],
                    fallbackType: 'strict',
                ),
            ],
        );
        $this->setUpFrontendRootPage(
            self::STORAGE_PAGE_ID,
            [],
            ['config' => 'config.tx_extbase.persistence.storagePid = ' . self::STORAGE_PAGE_ID . LF],
        );
    }

    protected function tearDown(): void
    {
        // The environment is restored by execute() already; this guards against
        // a leftover state when a test fails before or outside of it.
        $this->get(StateManagerInterface::class)->reset();

        parent::tearDown();
    }

    /**
     * Runs the closure in a frontend environment built for the given site
     * language, and restores the previous environment in every case.
     */
    protected function executeInFrontendContext(\Closure $work, int $languageId = 0): void
    {
        $this->get(StateManagerInterface::class)->execute(
            new StateBuildContext(
                applicationType: ApplicationType::FRONTEND,
                pageId: self::STORAGE_PAGE_ID,
                languageId: $languageId,
            ),
            $work,
        );
    }

    /**
     * Reads a single column of a table straight from the database, bypassing
     * every Extbase layer.
     *
     * Assertions about what was *written* have to be made against the raw rows:
     * reading the value back through the same mapper that wrote it would pass
     * for an object that was never persisted at all.
     *
     * @return array<int, int|string|null> the column value per uid
     */
    protected function readColumnByUid(string $table, string $column, string $orderBy = 'uid'): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', $column)
            ->from($table)
            ->orderBy($orderBy)
            ->executeQuery()
            ->fetchAllAssociative();

        $values = [];
        foreach ($rows as $row) {
            /** @var int|string|null $value */
            $value = $row[$column];
            $values[(int)$row['uid']] = $value;
        }

        return $values;
    }

    /**
     * The same as {@see readColumnByUid()} for an integer column.
     *
     * The cast is not cosmetic: Doctrine hands integer columns back as `int`
     * on SQLite and PostgreSQL and as `string` on MySQL and MariaDB, so an
     * assertion comparing the raw values with `assertSame()` would pass on
     * three of the four database platforms this suite runs on.
     *
     * @return array<int, int> the column value per uid
     */
    protected function readIntColumnByUid(string $table, string $column, string $orderBy = 'uid'): array
    {
        return array_map(
            static fn(int|string|null $value): int => (int)$value,
            $this->readColumnByUid($table, $column, $orderBy),
        );
    }
}
