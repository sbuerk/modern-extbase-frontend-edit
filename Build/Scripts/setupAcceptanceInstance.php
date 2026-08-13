<?php

declare(strict_types=1);

/*
 * Builds the TYPO3 instance the acceptance suite drives with a real browser.
 *
 * It is a CLI script and not a test case, because nothing here is an assertion:
 * it produces a bootable frontend under
 * `.Build/Web/typo3temp/var/tests/acceptance/`, seeds it from the same CSV
 * fixtures the functional suite uses, and writes a manifest the Playwright side
 * reads. `Build/Scripts/runTests.sh -s acceptance` runs it before it starts the
 * web server.
 *
 * ## Why `Testbase` and not `vendor/bin/typo3 setup`
 *
 * `SetupCommand` lives in `typo3/cms-install`, which is not part of this
 * dependency set - `typo3/minimal` brings core, backend, frontend, extbase,
 * fluid, filelist and the CLI, and nothing else. Adding the install tool as a
 * dev dependency for a browser suite would grow the dependency set of both core
 * versions for every other gate as well.
 *
 * `typo3/testing-framework`'s `Testbase` builds exactly this kind of instance
 * and says so: `setUpInstanceCoreLinks()` rewrites the entry point to
 * `\TYPO3\TestingFramework\Core\SystemEnvironmentBuilder::run(0, 0, false)`
 * "because acceptance tests will make use of them", i.e. a **non composer mode**
 * instance with its own `index.php`, its own `typo3conf/system/settings.php` and
 * its own database. That means no second composer project, and in particular no
 * `config/system/settings.php` written into the repository root - which
 * `typo3/cms-composer-installers` v5 would force, since it removed `app-dir`
 * and always uses the composer root.
 *
 * ## The one thing `Testbase` cannot do for us
 *
 * The generated `index.php` requires `.Build/vendor/autoload.php`, whose
 * `files` autoload pulls in `typo3/autoload-include.php`. That file sets
 * `TYPO3_PATH_ROOT` to `.Build/Web` and `TYPO3_PATH_APP` to the repository root
 * unless they are already set, and `SystemEnvironmentBuilder` then rewrites the
 * site path away from the instance. In a functional test that never happens
 * because `FunctionalTestCase::setUp()` calls `putenv()` before requiring the
 * autoloader; a web request has nobody to do that. {@see writeEntryPoint()}
 * therefore injects the two `putenv()` calls at the top of the entry point.
 */

use SBUERK\ModernExtbaseFrontendEdit\Controller\ProfileAjaxController;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Session\UserSessionManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\DataSet;
use TYPO3\TestingFramework\Core\Testbase;

if (PHP_SAPI !== 'cli') {
    die('This script supports command line usage only.');
}

$rootPath = dirname(__DIR__, 2);
$testsPath = $rootPath . '/.Build/Web/typo3temp/var/tests';
$instancePath = $testsPath . '/acceptance';
// The database lives *next to* the instance rather than inside it: the instance
// directory is the document root, and a SQLite file below it would be one
// unlucky rewrite rule away from being downloadable.
$databasePath = $testsPath . '/acceptance-db';
$databaseFile = $databasePath . '/acceptance.sqlite';
$pristineFile = $databasePath . '/acceptance.pristine.sqlite';
$manifestFile = $testsPath . '/acceptance.json';

// The instance points at itself before anything bootstraps. Everything below
// depends on this, including the entry point written by writeEntryPoint().
putenv('TYPO3_PATH_ROOT=' . $instancePath);
putenv('TYPO3_PATH_APP=' . $instancePath);

require $rootPath . '/.Build/vendor/autoload.php';

/**
 * The site the browser talks to.
 *
 * `web` is the network alias of the apache container on the `runTests.sh`
 * network, so this is the host the browser resolves *and* the host TYPO3 has to
 * accept for site routing to resolve. The two have to be the same string, which
 * is why no port is published for the CI run.
 */
const ACCEPTANCE_BASE_URL = 'http://web/';

/**
 * The fixture page holding the edit plugin, i.e. the page every spec starts on.
 */
const ACCEPTANCE_EDIT_PAGE_PATH = '/edit-profile';

/**
 * The two sites that *pin* a colour scheme, and the edit page of each.
 *
 * `devSite.colorScheme` is a **site** setting, so exercising it needs a second
 * site rather than a second page: one site cannot answer the setting two ways.
 * Each of these carries the same edit plugin over the same profile, and differs
 * from `acme` in exactly one value.
 *
 * They are separated by base **path** rather than by host. A second host would
 * work — the session JWT is deliberately scopeless and `trustedHostsPattern` is
 * `.*` — but it would need a second `--network-alias` on the apache container in
 * both the docker and the podman branch of `runTests.sh`, which is infrastructure
 * to maintain for a difference no test observes.
 *
 * @var array<string, array{rootPageId: int, editPageUid: int, path: string}>
 */
const ACCEPTANCE_PINNED_SCHEME_SITES = [
    'dark' => ['rootPageId' => 100, 'editPageUid' => 101, 'path' => '/dark'],
    'light' => ['rootPageId' => 110, 'editPageUid' => 111, 'path' => '/light'],
];

/**
 * The frontend users a spec can act as, and what each of them is good for.
 *
 * The uids are the ones of `ProfilePlugins.csv` and `ProfileEditPlugin.csv`.
 */
const ACCEPTANCE_FRONTEND_USERS = [
    // Owns the profile of "Ada Lovelace" with four addresses - one of them
    // hidden - and two e-mail addresses.
    'owner' => 1,
    // Owns the profile of "Radia Perlman". The session every "not yours" case
    // is made from.
    'other' => 2,
    // Logged in and owns no profile at all.
    'profileless' => 3,
];

/**
 * The tables the reset between specs is verified against.
 *
 * @var list<string>
 */
const ACCEPTANCE_RECORD_TABLES = [
    'tx_modernextbasefrontendedit_domain_model_profile',
    'tx_modernextbasefrontendedit_domain_model_address',
    'tx_modernextbasefrontendedit_domain_model_email',
];

$testbase = new Testbase();

echo 'Removing an earlier acceptance instance ... ';
$testbase->removeOldInstanceIfExists($instancePath);
GeneralUtility::rmdir($databasePath, true);
echo 'done' . PHP_EOL;

echo 'Creating the instance directory structure ... ';
$testbase->createDirectory($instancePath . '/fileadmin/_temp_');
$testbase->createDirectory($instancePath . '/typo3temp/var/transient');
$testbase->createDirectory($instancePath . '/typo3temp/assets');
$testbase->createDirectory($instancePath . '/typo3conf/ext');
$testbase->createDirectory($instancePath . '/typo3conf/sites/acme');
foreach (array_keys(ACCEPTANCE_PINNED_SCHEME_SITES) as $scheme) {
    $testbase->createDirectory($instancePath . '/typo3conf/sites/acme-' . $scheme);
}
$testbase->createDirectory($databasePath);
echo 'done' . PHP_EOL;

/**
 * EXT:fluid_styled_content carries `lib.contentElement`, which every plugin of
 * this extension is rendered through - the same reason
 * `AbstractProfilePluginTestCase` loads it.
 */
$defaultCoreExtensionsToLoad = ['core', 'backend', 'frontend', 'extbase', 'fluid'];
$coreExtensionsToLoad = ['fluid_styled_content'];

/**
 * This extension, and the development site package it is photographed inside.
 *
 * `tests/dev-site` (extension key `test_dev_site`) provides the `page` object,
 * the page templates and the theme. It is `require-dev` through a composer path
 * repository and is never published; `packages/` is `export-ignore`d.
 *
 * Both entries are composer package names rather than extension keys, because
 * that is what `linkTestExtensionsToInstance()` resolves - and for this package
 * the two differ in a way nothing derives: `tests/dev-site` installs as
 * `test_dev_site`, stated by `extra.typo3/cms.extension-key` in its manifest.
 */
$testExtensionsToLoad = [
    'sbuerk/modern-extbase-frontend-edit',
    'tests/dev-site',
];

echo 'Linking core extensions and this extension into the instance ... ';
$testbase->setUpInstanceCoreLinks($instancePath, $defaultCoreExtensionsToLoad, $coreExtensionsToLoad);
$testbase->linkTestExtensionsToInstance($instancePath, $testExtensionsToLoad);
echo 'done' . PHP_EOL;

echo 'Writing the entry point, the .htaccess and the settings ... ';
writeEntryPoint($instancePath);
writeHtaccess($instancePath);

$testbase->setUpLocalConfiguration(
    $instancePath,
    [
        'DB' => [
            'Connections' => [
                'Default' => [
                    'driver' => 'pdo_sqlite',
                    'path' => $databaseFile,
                    'charset' => 'utf8',
                    // One SQLite file, one apache, one php-fpm pool and a
                    // browser firing AJAX at it while a page is still loading:
                    // "database is locked" is the failure mode this prevents,
                    // and core ships the identical block for its own Playwright
                    // instance. WAL lets a reader and a writer coexist, and the
                    // busy timeout turns a collision into a wait rather than an
                    // error.
                    'driverOptions' => [
                        \PDO::ATTR_TIMEOUT => 120,
                    ],
                    'initCommands' => implode("\n", [
                        'PRAGMA journal_mode = WAL;',
                        'PRAGMA busy_timeout = 120000;',
                        'PRAGMA synchronous = NORMAL;',
                    ]),
                ],
            ],
        ],
        'SYS' => [
            'displayErrors' => 1,
            'devIPmask' => '*',
            'trustedHostsPattern' => '.*',
            'encryptionKey' => 'acceptance-instance-is-not-a-secure-encryption-key',
            'sitename' => 'Acceptance',
            'caching' => [
                'cacheConfigurations' => [
                    // The page cache is off, and that is a test design decision
                    // rather than a performance one: a spec reloads the page to
                    // prove that the *server* serves the changed value, and a
                    // cached rendering would let that assertion pass over a
                    // write that never happened. The same argument applies to
                    // the caches derived from it. It also keeps the SQLite file
                    // out of the cache write path, which is where the locking
                    // risk above is largest.
                    'hash' => ['backend' => \TYPO3\CMS\Core\Cache\Backend\NullBackend::class],
                    'pages' => ['backend' => \TYPO3\CMS\Core\Cache\Backend\NullBackend::class],
                    'rootline' => ['backend' => \TYPO3\CMS\Core\Cache\Backend\NullBackend::class],
                    'imagesizes' => ['backend' => \TYPO3\CMS\Core\Cache\Backend\NullBackend::class],
                ],
            ],
        ],
        'FE' => [
            // The session is created by this script in a CLI process, where
            // there is no remote address to lock it to, and it is then used by
            // a browser in a different container. Locking would refuse it.
            'lockIP' => 0,
            'lockIPv6' => 0,
            // Long enough that a whole suite run cannot outlive a session.
            'sessionTimeout' => 86400,
        ],
        'GFX' => [
            'processor' => 'GraphicsMagick',
        ],
        /*
         * The class names the surface hands to its own elements, on top of the
         * "frontend-edit-*" ones it always carries.
         *
         * This is the seam a project configures, and configuring it here is what
         * makes the acceptance instance prove that the seam works: the values
         * below are the site package's own class names, so a themed button in a
         * screenshot is evidence that an integrator can do the same thing.
         *
         * It is also the only place the two packages know about each other.
         * Neither the extension nor `test_dev_site` names the other; this
         * settings block is the wiring, exactly as it would be in a project.
         */
        'modern_extbase_frontend_edit' => [
            'classes' => [
                'button' => 'button',
                'buttonPrimary' => 'button--primary',
                'buttonDanger' => 'button--danger',
                'buttonIconOnly' => 'button--icon',
                'control' => 'form-control',
                'label' => 'form-label',
                'errors' => 'form-errors',
                'filePicker' => 'file-picker',
            ],
        ],
        'MAIL' => [
            // Nothing sends mail here, and a transport that tries to would turn
            // a failure into an exception page.
            'transport' => 'null',
        ],
    ],
    [],
);

$testbase->setUpPackageStates(
    $instancePath,
    $defaultCoreExtensionsToLoad,
    $coreExtensionsToLoad,
    $testExtensionsToLoad,
    [],
);
echo 'done' . PHP_EOL;

echo 'Bootstrapping TYPO3 and creating the database schema ... ';
$container = $testbase->setUpBasicTypo3Bootstrap($instancePath);
$testbase->setUpTestDatabase($databaseFile, '');
$testbase->createDatabaseStructure($container);
$testbase->loadExtensionTables();
echo 'done' . PHP_EOL;

echo 'Importing the fixture records ... ';
// The very fixtures the functional suite runs on. Reused rather than copied so
// that a record added for a functional test is in the browser run as well, and
// so that the two cannot describe different profiles.
foreach ([
    'ProfileSite.csv',
    'ProfilePlugins.csv',
    'ProfileEditPlugin.csv',
    'ProfileAjaxRecords.csv',
    // Carries the `sys_file_storage` record, without which no upload can
    // resolve its target folder at all — and the two indexed files and the
    // profiles referencing them, so that the browser run and the functional run
    // describe the same FAL fixture.
    'ProfileImages.csv',
] as $fixture) {
    DataSet::import($rootPath . '/Tests/Functional/Fixtures/Database/' . $fixture);
}
// The two page trees the colour scheme sites are rooted on. This one is *not*
// shared with the functional suite: a second and a third site root exist only so
// that a browser can ask for a pinned scheme, and adding them to the fixtures
// every functional test imports would change the page tree of tests that have
// nothing to do with colour.
DataSet::import($rootPath . '/Tests/Acceptance/Fixtures/Database/PinnedColorSchemeSites.csv');
provideFixtureFiles($rootPath, $instancePath);
echo 'done' . PHP_EOL;

echo 'Writing the site configuration ... ';
writeSiteConfiguration($instancePath);
echo 'done' . PHP_EOL;

echo 'Creating the frontend user sessions ... ';
$sessions = createFrontendUserSessions();
echo 'done' . PHP_EOL;

echo 'Snapshotting the database ... ';
$rowCounts = snapshotDatabase($databaseFile, $pristineFile);
echo 'done' . PHP_EOL;

writeManifest($manifestFile, [
    'baseUrl' => ACCEPTANCE_BASE_URL,
    'editPagePath' => ACCEPTANCE_EDIT_PAGE_PATH,
    // Keyed by the scheme each site pins, which is what a spec selects by.
    'pinnedSchemeEditPagePaths' => array_map(
        static fn(array $site): string => $site['path'] . ACCEPTANCE_EDIT_PAGE_PATH,
        ACCEPTANCE_PINNED_SCHEME_SITES,
    ),
    'instancePath' => $instancePath,
    'databaseFile' => $databaseFile,
    'pristineDatabaseFile' => $pristineFile,
    'sessionCookieName' => 'fe_typo_user',
    'sessions' => $sessions,
    'pristineRowCounts' => $rowCounts,
    'requestTokenScope' => ProfileAjaxController::REQUEST_TOKEN_SCOPE,
]);

echo PHP_EOL . 'Acceptance instance ready at ' . $instancePath . PHP_EOL;
echo 'Manifest written to ' . $manifestFile . PHP_EOL;

/**
 * Copies the committed fixture images into `fileadmin/` of the instance.
 *
 * The counterpart of `$pathsToProvideInTestInstance` in the functional suite,
 * and copied for the same reason it is copied there: `fileadmin/user_upload/`
 * is the folder an upload writes into, and behind a symlink a browser run that
 * stores, replaces or deletes a file would be writing into the repository
 * working tree.
 *
 * Without this the two `sys_file` rows of `ProfileImages.csv` would name files
 * that are not there — an upload would still work, and every page rendering one
 * of those two profiles would serve a broken image.
 */
function provideFixtureFiles(string $rootPath, string $instancePath): void
{
    $source = $rootPath . '/Tests/Functional/Fixtures/Files/';
    $target = $instancePath . '/fileadmin/user_upload/';
    GeneralUtility::mkdir_deep($target);

    foreach (new \DirectoryIterator($source) as $file) {
        if ($file->isFile() && !copy($file->getPathname(), $target . $file->getFilename())) {
            throw new \RuntimeException(
                sprintf('The fixture file "%s" could not be copied into the instance.', $file->getFilename()),
                1786800005,
            );
        }
    }
}

/**
 * Rewrites the entry point `Testbase` generated so that it points the instance
 * at itself before the composer autoloader runs.
 *
 * See the file docblock: `typo3/autoload-include.php` would otherwise claim
 * `TYPO3_PATH_ROOT` for `.Build/Web`, and `SystemEnvironmentBuilder` prefers
 * that environment variable over the directory the entry script actually lives
 * in - which is the whole mechanism the instance is built on.
 *
 * The insertion is asserted rather than assumed: a `Testbase` release that
 * changes the shape of the generated file has to be noticed here and not three
 * container starts later in an HTTP 500.
 */
function writeEntryPoint(string $instancePath): void
{
    $entryPoint = $instancePath . '/index.php';
    $generated = file_get_contents($entryPoint);
    if (!is_string($generated) || !str_contains($generated, 'SystemEnvironmentBuilder::run')) {
        throw new \RuntimeException(
            sprintf('The entry point generated at "%s" is not the expected one.', $entryPoint),
            1786800001,
        );
    }

    $prelude = <<<'PHP'
        <?php

        // Written by Build/Scripts/setupAcceptanceInstance.php.
        //
        // The two lines below are the reason this file is patched rather than
        // used as the testing framework generated it. Requiring the composer
        // autoloader pulls in "typo3/autoload-include.php", which sets
        // TYPO3_PATH_ROOT to the composer web-dir and TYPO3_PATH_APP to the
        // composer root unless they are already set - and
        // SystemEnvironmentBuilder prefers those over the directory this script
        // is in. Setting them here is what keeps this instance self contained.
        putenv('TYPO3_PATH_ROOT=' . __DIR__);
        putenv('TYPO3_PATH_APP=' . __DIR__);
        PHP;

    $patched = preg_replace('/^<\?php/', $prelude, $generated, 1);
    if (!is_string($patched) || $patched === $generated) {
        throw new \RuntimeException(
            sprintf('The entry point at "%s" could not be patched.', $entryPoint),
            1786800002,
        );
    }

    file_put_contents($entryPoint, $patched);
}

/**
 * The rewrite rules that make speaking URLs resolve.
 *
 * `Testbase::provideInstance()` would normally copy TYPO3's own `.htaccess` out
 * of EXT:install, which is not installed here - see the file docblock. What the
 * instance actually needs is the front controller rule and nothing else: no
 * compression, no expiration headers, no security headers, none of which any
 * spec asserts. The apache image grants `AllowOverride All` and
 * `+FollowSymLinks` on the document root, so the symlinked `typo3/sysext/` and
 * `typo3conf/ext/` assets are served as static files by apache and never reach
 * php-fpm.
 */
function writeHtaccess(string $instancePath): void
{
    $htaccess = <<<'HTACCESS'
        # Front controller of the acceptance instance.
        # Written by Build/Scripts/setupAcceptanceInstance.php.
        <IfModule mod_rewrite.c>
            RewriteEngine On

            # Everything that exists on disk is served by apache directly. That
            # is the extension's own Resources/Public/ assets and core's, both
            # reached through the symlinks the testing framework created.
            RewriteCond %{REQUEST_FILENAME} -f [OR]
            RewriteCond %{REQUEST_FILENAME} -d
            RewriteRule ^ - [L]

            RewriteRule ^ index.php [QSA,L]
        </IfModule>
        HTACCESS;

    file_put_contents($instancePath . '/.htaccess', $htaccess . "\n");
}

/**
 * The site the specs browse, configured through **site sets**.
 *
 * The set flavour is chosen over a `sys_template` record for one reason: it
 * needs no record at all, so the whole TypoScript configuration of the instance
 * is one file a human can read. Both flavours are covered by the functional
 * suite - `ProfilePluginSiteSetTest` against this one - so choosing here costs
 * no coverage.
 *
 * ## The `page` object comes from a site package now
 *
 * It used to be written here as an inline `setup.typoscript`, under a comment
 * saying it was "the part a site package would provide and this extension
 * deliberately does not". That was true and it was also why every screenshot in
 * the manual showed the editing surface on a bare white page: there was no
 * theme, so there was nothing for the surface to look like it belonged to.
 *
 * `tests/dev-site` is that site package. It is a `require-dev` path repository,
 * it is never published, and it brings the `page` object, a header, a footer and
 * a stylesheet with a light and a dark scheme. Adding its set here is the whole
 * wiring - `test_dev_site` depends on `typo3/fluid-styled-content` itself, so
 * that entry moves into the package and out of this list.
 */
function writeSiteConfiguration(string $instancePath): void
{
    writeSite($instancePath, 'acme', buildSiteConfiguration(1, ACCEPTANCE_BASE_URL, 'ACME', 4));

    // One site per pinned scheme. Everything about them is the site above except
    // the root page, the base path and the one setting under test - which is the
    // point: a difference the specs observe has to be the only difference there
    // is, or the observation proves nothing about the setting.
    foreach (ACCEPTANCE_PINNED_SCHEME_SITES as $scheme => $site) {
        $configuration = buildSiteConfiguration(
            $site['rootPageId'],
            rtrim(ACCEPTANCE_BASE_URL, '/') . $site['path'] . '/',
            'ACME (' . $scheme . ')',
            $site['editPageUid'],
        );
        // Nested rather than flat: `SiteSettings` flattens the tree it is given
        // with `ArrayUtility::flattenPlain()`, so this resolves as the
        // `devSite.colorScheme` the site set defines and the page reads through
        // `data = sitesettings:devSite.colorScheme`.
        $configuration['settings']['devSite']['colorScheme'] = $scheme;

        writeSite($instancePath, 'acme-' . $scheme, $configuration);
    }
}

/**
 * @return array<string, mixed>
 */
function buildSiteConfiguration(int $rootPageId, string $base, string $websiteTitle, int $editPageUid): array
{
    return [
        'rootPageId' => $rootPageId,
        'base' => $base,
        'websiteTitle' => $websiteTitle,
        'dependencies' => [
            'tests/dev-site',
            'sbuerk/modern-extbase-frontend-edit',
        ],
        'languages' => [
            [
                'title' => 'English',
                'enabled' => true,
                'languageId' => 0,
                'base' => '/',
                'locale' => 'en_US.UTF-8',
                'navigationTitle' => 'English',
                'flag' => 'us',
                'websiteTitle' => '',
            ],
        ],
        'errorHandling' => [],
        'routes' => [],
        'settings' => [
            'modernextbasefrontendedit' => [
                'persistence' => [
                    // A comma separated page uid list, hence a string. The same
                    // storage for every site: the profile under test is one
                    // record, and a second copy of it per site would let two
                    // sites disagree about the fixture.
                    'storagePid' => '1',
                ],
                // Page 3 carries the show plugin and lives in `acme`. The colour
                // scheme sites render the edit plugin and nothing else, so this
                // is never resolved into a link there; it is set rather than
                // omitted so the three sites differ only where they are meant to.
                'showPageUid' => 3,
                'editPageUid' => $editPageUid,
            ],
        ],
    ];
}

/**
 * @param array<string, mixed> $configuration
 */
function writeSite(string $instancePath, string $identifier, array $configuration): void
{
    file_put_contents(
        $instancePath . '/typo3conf/sites/' . $identifier . '/config.yaml',
        Yaml::dump($configuration, 99, 2),
    );
}

/**
 * One logged-in frontend user session per fixture user, as cookie values.
 *
 * This is the acceptance counterpart of
 * `InternalRequestContext::withFrontendUserId()`, and it is built the same way
 * the testing framework builds it in
 * `json_response/Classes/Middleware/FrontendUserHandler.php`: an anonymous
 * session, elevated to a fixated user session, and the JWT of that session as
 * the `fe_typo_user` cookie value. Playwright adds the cookie to a browser
 * context, and core's `FrontendUserAuthenticator` then finds a valid session
 * and logs the user in - so nothing about the authentication path is faked,
 * only the login form is skipped. EXT:felogin is not a dependency of this
 * extension and adding one for a test harness would be the wrong trade.
 *
 * `getJwt()` is called without a `CookieScope`, exactly as the framework does.
 * A scope would pin the cookie to a host and a path, and
 * `UserSession::resolveIdentifierFromJwt()` accepts a scopeless token on any
 * host - which is what makes the same manifest usable whether the browser
 * reaches the site as `http://web/` or through a published port.
 *
 * @return array<string, array{userId: int, cookie: string}>
 */
function createFrontendUserSessions(): array
{
    $sessions = [];
    foreach (ACCEPTANCE_FRONTEND_USERS as $name => $userId) {
        $userSessionManager = UserSessionManager::create('FE');
        $userSession = $userSessionManager->createAnonymousSession();
        $userSessionManager->elevateToFixatedUserSession($userSession, $userId, true);
        $sessions[$name] = [
            'userId' => $userId,
            'cookie' => $userSession->getJwt(),
        ];
    }

    return $sessions;
}

/**
 * Copies the seeded database aside, and reports what is in it.
 *
 * Both halves matter. The copy is what every spec is reset from; the row counts
 * name the tables that reset is verified over and assert that the seeding
 * actually produced rows - they are read here, from the database the server will
 * use, rather than counted from the CSV files, so a fixture row that never
 * arrived is caught as well. The reset itself compares the two databases row by
 * row rather than counting, and says why in `Tests/Acceptance/fixtures.ts`.
 *
 * The checkpoint is not decoration. The connection above runs
 * `PRAGMA journal_mode = WAL`, so at this point some of what was written lives
 * in `acceptance.sqlite-wal` and not in `acceptance.sqlite`. Copying the main
 * file alone would snapshot a database missing its most recent writes.
 *
 * @return array<string, int>
 */
function snapshotDatabase(string $databaseFile, string $pristineFile): array
{
    $connection = GeneralUtility::makeInstance(ConnectionPool::class)
        ->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);

    // Raw counts, deliberately not through `Connection::count()`: that one
    // builds a TYPO3 QueryBuilder, which carries the default restrictions and
    // would leave out the deleted and the hidden fixture rows - and those are
    // exactly the rows a reset has to bring back.
    $rowCounts = [];
    foreach (ACCEPTANCE_RECORD_TABLES as $table) {
        $rowCounts[$table] = (int)$connection
            ->executeQuery('SELECT COUNT(uid) FROM ' . $connection->quoteIdentifier($table))
            ->fetchOne();
    }

    $connection->executeStatement('PRAGMA wal_checkpoint(TRUNCATE);');
    $connection->close();

    foreach (['-wal', '-shm'] as $sidecar) {
        if (is_file($databaseFile . $sidecar)) {
            unlink($databaseFile . $sidecar);
        }
    }

    if (!copy($databaseFile, $pristineFile)) {
        throw new \RuntimeException(
            sprintf('The database snapshot "%s" could not be written.', $pristineFile),
            1786800003,
        );
    }

    // Proves the snapshot is a usable database and not a truncated copy, which
    // is the one failure that would otherwise surface as every spec failing at
    // once for no visible reason.
    $probe = new \PDO('sqlite:' . $pristineFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    foreach ($rowCounts as $table => $expected) {
        $counted = (int)$probe->query('SELECT COUNT(uid) FROM ' . $table)->fetchColumn();
        if ($counted !== $expected) {
            throw new \RuntimeException(
                sprintf(
                    'The database snapshot holds %d rows of "%s" where the seeded database holds %d.',
                    $counted,
                    $table,
                    $expected,
                ),
                1786800004,
            );
        }
    }

    return $rowCounts;
}

/**
 * @param array<string, mixed> $manifest
 */
function writeManifest(string $manifestFile, array $manifest): void
{
    file_put_contents(
        $manifestFile,
        json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
}
