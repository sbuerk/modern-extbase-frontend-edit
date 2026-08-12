<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Verifies the "sbuerk/fixture-packages" integration.
 *
 * The subject of these tests is the wiring, not the fixture extension: that a
 * fixture extension below "Tests/Functional/Fixtures/Extensions/" can be named
 * in $testExtensionsToLoad by its composer package name and is then loaded
 * under both identifiers TYPO3 and composer know it by.
 *
 * Both spellings are asserted because they are resolved differently and only
 * one of them is exercised elsewhere: every other test in this repository that
 * loads a fixture extension names it by its composer package name, so nothing
 * but this test would notice if resolution by extension key broke.
 *
 * The wiring under test is the bootstrap of the functional suite, which adopts
 * every fixture package before the first test runs — see
 * "Build/phpunit/FunctionalTestsBootstrap.php".
 */
final class FixturePackagesTest extends AbstractFunctionalTestCase
{
    /**
     * The fixture extension is loaded by its composer package name, which is
     * exactly what is under test here. The extension itself is repeated from
     * the parent class, because redeclaring the property replaces it.
     */
    protected array $testExtensionsToLoad = [
        'sbuerk/modern-extbase-frontend-edit',
        'tests/workspace-fixture',
    ];

    public static function fixtureExtensionIdentifiers(): \Generator
    {
        yield 'composer package name: tests/workspace-fixture' => ['identifier' => 'tests/workspace-fixture'];
        yield 'extension key: tests_workspace_fixture' => ['identifier' => 'tests_workspace_fixture'];
    }

    #[DataProvider('fixtureExtensionIdentifiers')]
    #[Test]
    public function fixtureExtensionIsLoadedInTestInstance(string $identifier): void
    {
        $this->assertTrue(ExtensionManagementUtility::isLoaded($identifier), sprintf(
            '"%s" returns true using identifier "%s".',
            sprintf('%s::%s()', ExtensionManagementUtility::class, 'isLoaded'),
            $identifier,
        ));
    }
}
