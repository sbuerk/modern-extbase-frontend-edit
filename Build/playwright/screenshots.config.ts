/**
 * Which screenshots the rendered documentation uses, and how each one is
 * reached.
 *
 * This is a configuration file rather than a set of test files because the
 * shots are *data*: adding one to the manual should be adding an entry here,
 * not writing a new spec. What cannot be data is the way a state is reached —
 * opening a field, typing into it, submitting and being refused — so `prepare`
 * is a function. That is also why this is TypeScript and not YAML: a
 * declarative format would need an interpreter for a miniature language, plus
 * its own validation, to express what four lines of page-object calls already
 * express and the type checker already verifies.
 *
 * Run with `Build/Scripts/runTests.sh -s screenshotDocumentation`.
 *
 * ## This is not a test suite, and must not become one
 *
 * The generator writes into the tracked tree, which no gate does, and nothing
 * verifies its output: a screenshot that no longer matches the interface is a
 * documentation defect a person notices, not a red build. It is deliberately
 * absent from the CI workflow, and its files are named `*.shots.ts` so the
 * acceptance configuration, which collects only `.spec.ts` files, can
 * never pick them up.
 *
 * ## Determinism
 *
 * Everything rendered here is fixed: the fixture profile, its birthday, the
 * seeded children. There are no animations and no transitions in the
 * stylesheet, and the database is restored from the snapshot before every shot.
 * What is *not* fixed is the font set, which comes from the Playwright image —
 * which is the reason generation is containerised and there is no way to run it
 * on a host.
 */
import type { Page } from '@playwright/test';
import type { Role } from '../../Tests/Acceptance/fixtures';
import type { ProfileEditPage } from '../../Tests/Acceptance/Support/profileEditPage';

export interface Shot {
    /** Identifies the shot, and selects it: `-- --grep edit-owner-idle`. */
    readonly name: string;
    /** Path below `Documentation/files/images/`, subdirectory included. */
    readonly output: string;
    /** `null` for a visitor who is not logged in. */
    readonly as: Role | null;
    /** Everything to do before the shutter. */
    readonly prepare?: (surface: ProfileEditPage, page: Page) => Promise<void>;
    /**
     * Element to clip to. Omitted, the whole page is taken. Resolved with
     * Playwright's own locator, so it pierces the shadow root the same way the
     * specs do.
     */
    readonly clip?: string;
    /** Overrides {@see defaults} for one shot. */
    readonly viewport?: { readonly width: number; readonly height: number };
    readonly javaScriptEnabled?: boolean;
}

export const defaults = {
    viewport: { width: 1280, height: 900 },
    /**
     * Twice the CSS resolution. A manual is read on the same displays the
     * interface is used on, and a 1x screenshot of text looks broken on all of
     * them. It costs about twice the bytes, which for AVIF is still under
     * 25 kB a shot.
     */
    deviceScaleFactor: 2,
    /**
     * AVIF, and `4:4:4` is not optional: the default `4:2:0` subsampling smears
     * coloured text and the one pixel focus outline of the editing surface,
     * which is precisely what several of these shots exist to show.
     */
    avif: { quality: 55, effort: 6, chromaSubsampling: '4:4:4' },
} as const;

export const shots: readonly Shot[] = [
    {
        name: 'edit-anonymous',
        output: 'frontend-edit/anonymous.avif',
        as: null,
        clip: '.modern-extbase-frontend-edit-profile-edit',
    },
    {
        name: 'edit-server-rendered',
        output: 'frontend-edit/server-rendered.avif',
        as: 'owner',
        javaScriptEnabled: false,
        clip: '.modern-extbase-frontend-edit-profile-edit',
    },
    {
        name: 'edit-owner-idle',
        output: 'frontend-edit/owner-view.avif',
        as: 'owner',
        prepare: async (surface: ProfileEditPage): Promise<void> => {
            await surface.waitForEnhancement();
        },
        clip: 'modern-extbase-frontend-edit-profile',
    },
    {
        name: 'edit-field-open',
        output: 'frontend-edit/field-open.avif',
        as: 'owner',
        prepare: async (surface: ProfileEditPage): Promise<void> => {
            await surface.waitForEnhancement();
            await surface.startFieldEdit('profile', 'firstname');
        },
        // Clipped to the one field, not to the whole surface: what this shot
        // illustrates is forty pixels of a page that is two thousand tall, and
        // a reader of the manual should not have to hunt for it.
        clip: 'modern-extbase-frontend-edit-field[data-focus="profile|firstname"]',
    },
    {
        name: 'edit-field-rejected',
        output: 'frontend-edit/field-rejected.avif',
        as: 'owner',
        prepare: async (surface: ProfileEditPage): Promise<void> => {
            await surface.waitForEnhancement();
            await surface.startFieldEdit('profile', 'shortname');
            await surface.type('profile', 'shortname', '');
            await surface.applyField('profile', 'shortname');
        },
        clip: 'modern-extbase-frontend-edit-field[data-focus="profile|shortname"]',
    },
    {
        name: 'edit-record-open',
        output: 'frontend-edit/record-open.avif',
        as: 'owner',
        prepare: async (surface: ProfileEditPage): Promise<void> => {
            await surface.waitForEnhancement();
            await surface.startRecordEdit('profile');
        },
        clip: 'modern-extbase-frontend-edit-profile',
    },
];
