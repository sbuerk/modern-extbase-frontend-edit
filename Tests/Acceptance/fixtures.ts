/**
 * The two things every spec of this suite needs: a database that is back at the
 * seeded state, and a browser that is logged in.
 *
 * ## The reset, and why it asserts
 *
 * Core does not reset between its Playwright specs. It runs them serially and
 * has each of them restore whatever it changed. That is not good enough here:
 * the whole point of this suite is to assert that a value **survived** a write
 * and a reload, and a leftover from an earlier spec is indistinguishable from a
 * write that worked.
 *
 * So each test starts from a byte copy of the snapshot the seeding script took.
 * The single most likely way for that to be subtly wrong is the SQLite WAL
 * sidecars: the instance runs with `journal_mode = WAL`, so `acceptance.sqlite`
 * is not necessarily the whole database - `acceptance.sqlite-wal` holds
 * everything written since the last checkpoint. Copying the main file over a
 * live database while leaving the `-wal` and `-shm` files in place restores
 * nothing at all, and a suite would still be green because most specs write
 * before they read.
 *
 * Two things guard that, and the second one is the one that matters:
 *
 * 1. The sidecars are removed **with** the file rather than left beside it.
 * 2. {@see resetDatabase} then compares the restored database with the snapshot
 *    row by row and fails when they differ.
 *
 * The second is not a row count, and the reason is worth writing down: removing
 * a child is a *soft* delete, so a count over the whole table is identical
 * before and after it. A reset that silently did nothing would pass a count and
 * fail nothing until some later spec happened to read a value an earlier one had
 * changed - which is exactly the kind of failure that gets blamed on the browser.
 * The comparison is made in one place, against the snapshot the server itself was
 * seeded from, and it is exact.
 *
 * Reading the files directly is possible because the repository is bind mounted
 * into the Playwright container the same way it is into the PHP containers.
 * `node:sqlite` is experimental in node 22; it is used in the harness, never in
 * a spec, and the snapshot is opened read only so that verifying it cannot
 * change it.
 *
 * ## The login
 *
 * There is no login form to fill in - EXT:felogin is not a dependency of this
 * extension, and adding one so that a test harness can click a button would be
 * the wrong trade. The seeding script created a real frontend user session per
 * fixture user with core's own `UserSessionManager`, exactly as the testing
 * framework does for functional tests, and handed over the `fe_typo_user`
 * cookie value. Everything after that cookie is the production code path:
 * core's `FrontendUserAuthenticator` finds the session and logs the user in.
 */
import { test as base, expect } from '@playwright/test';
import type { BrowserContext } from '@playwright/test';
import { DatabaseSync } from 'node:sqlite';
import * as fs from 'node:fs';
import { manifest } from './manifest';

/**
 * The fixture users, by what they are good for rather than by uid.
 */
export type Role = 'owner' | 'other' | 'profileless';

/**
 * The tables the reset is verified over: the three the endpoints write.
 */
const verifiedTables = Object.keys(manifest.pristineRowCounts);

/**
 * Every row of the verified tables, as one comparable string.
 *
 * Both sides are read through the same driver, so the values are typed the same
 * way and a plain serialization is a sound comparison. Blob columns - TYPO3's
 * `l10n_diffsource` is one - would serialize to `{}` and hide their content, so
 * they are hex encoded instead.
 */
function contentOf(file: string): string {
    const database = new DatabaseSync(file, { readOnly: true });
    try {
        return verifiedTables
            .map((table: string): string => {
                const rows = database.prepare(`SELECT * FROM ${table} ORDER BY uid`).all();

                return `${table}: ${JSON.stringify(rows, (_key: string, value: unknown): unknown =>
                    value instanceof Uint8Array ? Buffer.from(value).toString('hex') : value)}`;
            })
            .join('\n');
    } finally {
        database.close();
    }
}

/**
 * The snapshot content, read once. It cannot change while the suite runs.
 */
let snapshotContent: string | null = null;

/**
 * Restores the seeded database, and proves that it did.
 */
export function resetDatabase(): void {
    if (snapshotContent === null) {
        snapshotContent = contentOf(manifest.pristineDatabaseFile);
        for (const [table, expected] of Object.entries(manifest.pristineRowCounts)) {
            if (!snapshotContent.includes(`${table}: [`)) {
                throw new Error(`The snapshot carries no "${table}" at all, expected ${expected} rows.`);
            }
        }
    }

    for (const suffix of ['', '-wal', '-shm']) {
        fs.rmSync(manifest.databaseFile + suffix, { force: true });
    }
    fs.copyFileSync(manifest.pristineDatabaseFile, manifest.databaseFile);

    const restored = contentOf(manifest.databaseFile);
    if (restored !== snapshotContent) {
        throw new Error(
            'The database reset did not restore the seeded state. The copy of '
            + `"${manifest.pristineDatabaseFile}" over "${manifest.databaseFile}", or the removal `
            + 'of its WAL sidecars, did not take effect.',
        );
    }
}

/**
 * Reads one column of one row straight from the database.
 *
 * Used by the specs that assert a *write*, next to - never instead of - the
 * assertion that the reloaded page serves the new value. The page proves the
 * server means it; the row proves what was stored.
 */
export function readColumn(table: string, uid: number, column: string): string | number | null {
    const database = new DatabaseSync(manifest.databaseFile);
    try {
        const row = database.prepare(`SELECT ${column} AS value FROM ${table} WHERE uid = ?`).get(uid) as
            { value: string | number | null } | undefined;

        return row === undefined ? null : row.value;
    } finally {
        database.close();
    }
}

/**
 * Every uid of a child collection, in stored sorting order, hidden ones
 * included.
 */
export function childUidsInStoredOrder(table: string, profileUid: number): number[] {
    const database = new DatabaseSync(manifest.databaseFile);
    try {
        const rows = database
            .prepare(`SELECT uid FROM ${table} WHERE profile = ? AND deleted = 0 ORDER BY sorting`)
            .all(profileUid) as { uid: number }[];

        return rows.map((row): number => Number(row.uid));
    } finally {
        database.close();
    }
}

async function login(context: BrowserContext, role: Role): Promise<void> {
    const session = manifest.sessions[role];
    if (session === undefined) {
        throw new Error(`The acceptance instance has no session for the role "${role}".`);
    }
    await context.addCookies([
        {
            name: manifest.sessionCookieName,
            value: session.cookie,
            url: manifest.baseUrl,
        },
    ]);
}

export const test = base.extend<{
    freshDatabase: void;
    loginAs: (role: Role) => Promise<void>;
    pageErrors: string[];
}>({
    freshDatabase: [
        // eslint-disable-next-line no-empty-pattern
        async ({}, use): Promise<void> => {
            resetDatabase();
            await use();
        },
        { auto: true },
    ],

    loginAs: async ({ context }, use): Promise<void> => {
        await use((role: Role): Promise<void> => login(context, role));
    },

    /**
     * Everything the browser complained about while the test ran.
     *
     * A custom element that fails to upgrade because a module did not resolve
     * produces a red page and a silent DOM: the assertion that follows then
     * fails with "element not found", which is true and says nothing. Collecting
     * the console and the uncaught errors turns that into the actual message.
     */
    pageErrors: async ({ page }, use): Promise<void> => {
        const errors: string[] = [];
        page.on('pageerror', (error: Error): void => {
            errors.push(`pageerror: ${error.message}`);
        });
        page.on('console', (message): void => {
            if (message.type() === 'error') {
                errors.push(`console: ${message.text()}`);
            }
        });
        page.on('requestfailed', (request): void => {
            errors.push(`requestfailed: ${request.url()} - ${request.failure()?.errorText ?? 'unknown'}`);
        });
        await use(errors);
    },
});

export { expect };
