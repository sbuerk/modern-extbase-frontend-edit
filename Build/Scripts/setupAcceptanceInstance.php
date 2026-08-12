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
$testbase->createDirectory($databasePath);
echo 'done' . PHP_EOL;

/**
 * EXT:fluid_styled_content carries `lib.contentElement`, which every plugin of
 * this extension is rendered through - the same reason
 * `AbstractProfilePluginTestCase` loads it.
 */
$defaultCoreExtensionsToLoad = ['core', 'backend', 'frontend', 'extbase', 'fluid'];
$coreExtensionsToLoad = ['fluid_styled_content'];
$testExtensionsToLoad = ['sbuerk/modern-extbase-frontend-edit'];

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
] as $fixture) {
    DataSet::import($rootPath . '/Tests/Functional/Fixtures/Database/' . $fixture);
}
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
 * is two files a human can read next to each other. Both flavours are covered
 * by the functional suite - `ProfilePluginSiteSetTest` against this one - so
 * choosing here costs no coverage.
 *
 * The `page` object is the part a site package would provide and this extension
 * deliberately does not. It is written as the site's own `setup.typoscript`,
 * which is included after the sets (Feature #103439, TYPO3 v13.1), and is
 * character for character the one `AbstractProfilePluginTestCase` uses.
 */
function writeSiteConfiguration(string $instancePath): void
{
    $configuration = [
        'rootPageId' => 1,
        'base' => ACCEPTANCE_BASE_URL,
        'websiteTitle' => 'ACME',
        'dependencies' => [
            'typo3/fluid-styled-content',
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
                    // A comma separated page uid list, hence a string.
                    'storagePid' => '1',
                ],
                'showPageUid' => 3,
                'editPageUid' => 4,
            ],
        ],
    ];

    file_put_contents(
        $instancePath . '/typo3conf/sites/acme/config.yaml',
        Yaml::dump($configuration, 99, 2),
    );

    $pageTypoScript = <<<'TYPOSCRIPT'
        # The part a site package would provide, and this extension deliberately
        # does not: it renders the content elements of the default column and
        # defines no plugin TypoScript whatsoever.
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

    file_put_contents(
        $instancePath . '/typo3conf/sites/acme/setup.typoscript',
        $pageTypoScript . "\n",
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
