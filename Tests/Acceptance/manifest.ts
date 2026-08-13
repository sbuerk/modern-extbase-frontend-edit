/**
 * What `Build/Scripts/setupAcceptanceInstance.php` wrote about the instance.
 *
 * Everything the browser side needs to know about the seeded TYPO3 instance is
 * produced by the seeding script and read back here: the base URL, the paths of
 * the live and the pristine database, the session cookies, and the tables the
 * reset is verified over together with the number of rows each of them was
 * seeded with.
 *
 * The alternative - restating the fixture in TypeScript - was rejected because
 * the two would drift: a row added to `ProfilePlugins.csv` for a functional
 * test would leave the acceptance side describing a fixture that no longer
 * exists, and the failure would point at the harness rather than at the fixture.
 */
import * as fs from 'node:fs';
import * as path from 'node:path';

export interface SessionManifest {
    readonly userId: number;
    readonly cookie: string;
}

export interface AcceptanceManifest {
    readonly baseUrl: string;
    readonly editPagePath: string;
    /**
     * The edit page of each site that *pins* a colour scheme, keyed by the
     * scheme it pins.
     *
     * `devSite.colorScheme` is a site setting, so a pinned scheme is a second
     * site rather than a second page — see the constant of the same shape in
     * `Build/Scripts/setupAcceptanceInstance.php`.
     */
    readonly pinnedSchemeEditPagePaths: Readonly<Record<string, string>>;
    readonly instancePath: string;
    readonly databaseFile: string;
    readonly pristineDatabaseFile: string;
    readonly sessionCookieName: string;
    readonly sessions: Readonly<Record<string, SessionManifest>>;
    readonly pristineRowCounts: Readonly<Record<string, number>>;
    readonly requestTokenScope: string;
}

/**
 * The directory every test artifact of this repository lives under.
 *
 * `runTests.sh -s cleanTests` removes exactly this path, and the CI job uploads
 * from it, so a report written anywhere else is a file nobody cleans up and
 * nobody sees.
 */
const testsPath = path.resolve(__dirname, '../../.Build/Web/typo3temp/var/tests');

const manifestPath = path.join(testsPath, 'acceptance.json');

if (!fs.existsSync(manifestPath)) {
    throw new Error(
        `No acceptance instance found at ${manifestPath}. `
        + 'Run "Build/Scripts/runTests.sh -s acceptance", which seeds it first.',
    );
}

export const manifest: AcceptanceManifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

/**
 * An absolute path below the test artifact directory.
 */
export function artifactPath(name: string): string {
    return path.join(testsPath, name);
}
