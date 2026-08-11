<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\Frontend;

use Psr\Http\Message\ResponseInterface;
use SBUERK\ModernExtbaseFrontendEdit\Tests\Functional\AbstractProfileTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequestContext;

/**
 * Shared setup of the tests that render the `list` and the `show` plugin
 * through a real frontend request.
 *
 * These are the first tests that exercise the controller, the plugin
 * registration, the TypoScript and the Fluid templates together. Everything
 * below is deliberately end to end: a `tt_content` record carrying the
 * registered `CType`, the generated rendering definition, and the response body
 * of a frontend sub-request.
 *
 * ## Why EXT:fluid_styled_content is loaded
 *
 * `ExtensionUtility::configurePlugin()` generates
 * `tt_content.<signature> =< lib.contentElement` and nothing else — the content
 * object of a plugin *is* `lib.contentElement`, which is defined by
 * EXT:fluid_styled_content and by nothing in `cms-core`, `cms-frontend` or
 * `cms-extbase`. Its `Generic` template renders the plugin through
 * `<f:cObject typoscriptObjectPath="tt_content.{data.CType}.20" />`. Without
 * that extension both plugins render an empty string, and every assertion below
 * would fail for a reason that has nothing to do with this extension.
 *
 * Defining a substitute `lib.contentElement` in test TypoScript would make the
 * tests green while removing exactly the piece of the chain they exist to
 * cover, so the extension is loaded instead.
 *
 * ## The two TypoScript flavours
 *
 * {@see setUpProfilePluginRendering()} is the seam. It defaults to the classic
 * `sys_template` flavour; {@see ProfilePluginSiteSetTest} overrides it with the
 * site set flavour. Everything else — fixtures, request helpers, markup
 * helpers — is shared, so the same behaviour can be asserted for both.
 *
 * A `page` object is needed either way and is not part of this extension: a
 * plugin is rendered by the site package, and there is no site package here.
 * {@see PAGE_TYPOSCRIPT} is that missing piece and nothing more — it renders the
 * content elements of column `0` and defines no plugin TypoScript whatsoever.
 */
abstract class AbstractProfilePluginTestCase extends AbstractProfileTestCase
{
    /**
     * The page holding the `show` plugin, and the page the `list` plugin is
     * configured to link its entries to.
     */
    protected const SHOW_PAGE_ID = 3;

    /**
     * The page the edit link points at. It holds no plugin — the edit plugin is
     * a later change and the list deliberately does not assume it exists.
     */
    protected const EDIT_PAGE_ID = 4;

    /**
     * A page that is **not** the configured storage page, holding a profile
     * that is visible in every other respect.
     */
    protected const OUTSIDE_PAGE_ID = 5;

    /**
     * The frontend user owning the profiles of "Ada Lovelace", "Grace Hopper",
     * "Karen Sparck Jones" and "Anita Borg".
     */
    protected const OWNER_FRONTEND_USER_ID = 1;

    /**
     * The frontend user owning the profile of "Radia Perlman".
     */
    protected const OTHER_FRONTEND_USER_ID = 2;

    /**
     * The part a site package would normally provide. It renders the content
     * elements of the default column, and it deliberately contains no
     * `tt_content.modernextbasefrontendedit_*` definition: those come from
     * `ExtensionUtility::configurePlugin()` and are under test.
     */
    protected const PAGE_TYPOSCRIPT = <<<'TYPOSCRIPT'
        page = PAGE
        page {
            typeNum = 0

            10 = CONTENT
            10 {
                table = tt_content
                select {
                    orderBy = sorting
                    where = {#colPos} = 0
                }
            }
        }
        TYPOSCRIPT;

    /**
     * See the class docblock: `lib.contentElement` lives here.
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-fluid-styled-content',
    ];

    /**
     * `Profile/Details` renders the birthday with `f:format.date` and no format
     * argument, which makes the installation wide `SYS/ddmmyy` setting the
     * format. Pinning it here is what allows an assertion on the rendered date:
     * the default differs between the two target core versions, so a test
     * reading it would assert a different string per version.
     */
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'ddmmyy' => 'Y-m-d',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/ProfilePlugins.csv');
        $this->setUpProfilePluginRendering();
    }

    /**
     * Configures the TypoScript flavour under test.
     *
     * The classic `sys_template` flavour is the default because it is the one
     * an installation that has not adopted site sets uses.
     */
    protected function setUpProfilePluginRendering(): void
    {
        $this->setUpClassicTypoScriptFlavour();
    }

    /**
     * The classic flavour: a root `sys_template` record.
     *
     * `include_static_file` is not decoration. `SysTemplateTreeBuilder` adds the
     * TypoScript that `ExtensionUtility::configurePlugin()` registered — it is
     * stored under the `defaultContentRendering` key — only next to a static
     * include that registered itself in
     * `$GLOBALS['TYPO3_CONF_VARS']['FE']['contentRenderingTemplates']`, and
     * EXT:fluid_styled_content is the extension that does so. Without the
     * include the generated `tt_content.modernextbasefrontendedit_list`
     * definition never reaches the TypoScript tree.
     *
     * The plugin settings are set as **constants**, not as setup: that is the
     * integrator facing surface of the classic flavour, and it leaves the
     * `plugin.tx_modernextbasefrontendedit` setup that `ext_localconf.php`
     * registers — including the two `mvc` switches — under test rather than
     * overwritten.
     */
    protected function setUpClassicTypoScriptFlavour(): void
    {
        $this->setUpFrontendRootPage(
            self::STORAGE_PAGE_ID,
            [],
            [
                'include_static_file' => 'EXT:fluid_styled_content/Configuration/TypoScript/',
                'constants' => implode(LF, [
                    'plugin.tx_modernextbasefrontendedit.persistence.storagePid = ' . self::STORAGE_PAGE_ID,
                    'plugin.tx_modernextbasefrontendedit.settings.showPageUid = ' . self::SHOW_PAGE_ID,
                    'plugin.tx_modernextbasefrontendedit.settings.editPageUid = ' . self::EDIT_PAGE_ID,
                ]) . LF,
                'config' => self::PAGE_TYPOSCRIPT . LF,
            ],
        );
    }

    /**
     * The site set flavour: no `sys_template` record at all.
     *
     * The site depends on two sets, and the values the plugins read come from
     * the `settings` of the site configuration rather than from TypoScript
     * constants. `SysTemplateTreeBuilder::createSiteTemplateInclude()` adds the
     * generated plugin TypoScript unconditionally here, so no static include is
     * involved — which is exactly the difference between the flavours.
     *
     * The `page` object is written as the site's own `setup.typoscript`
     * (Feature #103439, TYPO3 v13.1), which is included after the sets. It is
     * written after `writeSiteConfiguration()` because that call removes the
     * site directory before writing into it.
     */
    protected function setUpSiteSetFlavour(): void
    {
        $this->writeSiteConfiguration(
            'acme',
            $this->buildSiteConfiguration(
                rootPageId: self::STORAGE_PAGE_ID,
                base: 'https://acme.com/',
                websiteTitle: 'ACME',
                additionalRootConfiguration: [
                    'dependencies' => [
                        'typo3/fluid-styled-content',
                        'sbuerk/modern-extbase-frontend-edit',
                    ],
                    'settings' => [
                        'modernextbasefrontendedit' => [
                            'persistence' => [
                                // Declared as a string: the setting is a comma
                                // separated page uid list, not a page uid.
                                'storagePid' => (string)self::STORAGE_PAGE_ID,
                            ],
                            'showPageUid' => self::SHOW_PAGE_ID,
                            'editPageUid' => self::EDIT_PAGE_ID,
                        ],
                    ],
                ],
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

        GeneralUtility::writeFile(
            $this->instancePath . '/typo3conf/sites/acme/setup.typoscript',
            self::PAGE_TYPOSCRIPT . LF,
            true,
        );

        $this->setUpFrontendRootPage(self::STORAGE_PAGE_ID, [], [], false);
    }

    /**
     * Renders the page carrying the `list` plugin.
     *
     * `null` means "no frontend user session at all", which is not the same as
     * a session of user `0` — the distinction is the whole point of the
     * ownership tests.
     */
    protected function renderListPlugin(?int $frontendUserId = null): ResponseInterface
    {
        return $this->renderUrl('https://acme.com/', $frontendUserId);
    }

    /**
     * Renders the page carrying the `show` plugin, for the given profile.
     */
    protected function renderShowPlugin(int $profileUid, ?int $frontendUserId = null): ResponseInterface
    {
        return $this->renderUrl($this->showPluginUri($profileUid), $frontendUserId);
    }

    /**
     * The URI of the `show` plugin for a profile uid, including a valid cHash.
     *
     * The cHash is not optional: `PageArgumentValidator` turns a request with
     * plugin arguments and no valid cHash into a 404 before Extbase is reached,
     * so a test constructing the URL by hand would assert a 404 that says
     * nothing about the plugin. The calculation mirrors
     * `PageArgumentValidator::getRelevantParametersFromCacheHashArgument()`:
     * the dynamic arguments plus the page id.
     *
     * The URL is built here rather than read out of the rendered list, so that
     * the `show` tests do not depend on the `list` plugin. That the list links
     * to the same place is asserted in {@see ProfileListPluginTest} instead.
     */
    protected function showPluginUri(int $profileUid): string
    {
        $pluginArguments = [
            'tx_modernextbasefrontendedit_show' => [
                'action' => 'show',
                'controller' => 'Profile',
                'profile' => (string)$profileUid,
            ],
        ];

        $cacheHashCalculator = $this->get(CacheHashCalculator::class);
        $cacheHash = $cacheHashCalculator->calculateCacheHash(
            $cacheHashCalculator->getRelevantParameters(
                HttpUtility::buildQueryString($pluginArguments + ['id' => self::SHOW_PAGE_ID]),
            ),
        );

        return 'https://acme.com/profiles'
            . HttpUtility::buildQueryString($pluginArguments + ['cHash' => $cacheHash], '?');
    }

    /**
     * The rendered profile cards of a response body, keyed by rendered name.
     *
     * Assertions about "the edit link is rendered for this profile and not for
     * that one" have to be made per card. Searching the whole body would pass
     * for a page that renders one edit link in the wrong place.
     *
     * @return array<string, string>
     */
    protected function profileCards(string $body): array
    {
        $chunks = explode('<div class="modern-extbase-frontend-edit-profile-card">', $body);
        array_shift($chunks);

        $cards = [];
        foreach ($chunks as $chunk) {
            if (preg_match('#<h[0-9] class="modern-extbase-frontend-edit-profile-name">(.*?)</h[0-9]>#s', $chunk, $matches) !== 1) {
                $this->fail('A rendered profile card carries no name heading: ' . $chunk);
            }
            $cards[trim($matches[1])] = $chunk;
        }

        return $cards;
    }

    /**
     * The first capturing group of every match, in the order they were
     * rendered.
     *
     * @return list<string>
     */
    protected function renderedInOrder(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $matches);

        return array_values(array_map(
            static fn(string $match): string => trim($match),
            $matches[1],
        ));
    }

    private function renderUrl(string $url, ?int $frontendUserId): ResponseInterface
    {
        $context = new InternalRequestContext();
        if ($frontendUserId !== null) {
            $context = $context->withFrontendUserId($frontendUserId);
        }

        return $this->executeFrontendSubRequest(new InternalRequest($url), $context);
    }
}
