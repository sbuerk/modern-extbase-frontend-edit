<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional;

use SBUERK\TYPO3\Testing\TestCase\FunctionalTestCase;

/**
 * Base class of all functional tests, taking care that the extension itself is
 * loaded in the test instance.
 *
 * It extends the `FunctionalTestCase` of `sbuerk/typo3-site-based-test-trait`
 * rather than the one of `typo3/testing-framework` directly. That class extends
 * the framework one and adds what a site based test needs, most notably a
 * `setUpFrontendRootPage()` which can set up a root page without creating a
 * `sys_template` record. Having every functional test go through this class
 * means the whole suite gains that without a second base class — see the
 * "Site based tests" page of the developer documentation in "docs/testing/".
 */
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    /**
     * Fluid Styled Content is loaded for every functional test, not only for
     * the ones that render a plugin.
     *
     * The extension declares it as a dependency, because
     * `ExtensionUtility::configurePlugin()` emits
     * `tt_content.<signature> =< lib.contentElement` and nothing but Fluid
     * Styled Content defines `lib.contentElement`. The testing framework
     * resolves that declaration against the packages active in the test
     * instance and aborts with "depends on package … which does not exist"
     * when it is missing — for *every* test, including those that never touch
     * a plugin. Declaring a dependency and not loading it here is therefore
     * not an option, and the failure it produces points at the test instance
     * rather than at the declaration that caused it.
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-fluid-styled-content',
    ];

    protected array $testExtensionsToLoad = [
        'sbuerk/modern-extbase-frontend-edit',
    ];
}
