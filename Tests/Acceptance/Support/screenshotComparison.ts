/**
 * Compares a freshly taken documentation screenshot with the committed one.
 *
 * ## Why this is not `toHaveScreenshot()`
 *
 * Playwright's own image assertion writes and compares baselines it owns, in a
 * directory it chooses, in a format it chooses. The documentation screenshots
 * are none of those things: they are AVIF, at twice the CSS resolution, in
 * `Documentation/files/images/`, and they are read by a person rather than by a
 * runner. They exist for the manual and the check is the afterthought, so the
 * check adapts to them and not the other way round.
 *
 * ## Why pixels and not bytes
 *
 * A byte comparison is one line and was tried first. Both sides come out of one
 * encoder with one setting, and two full runs of the generator on one machine
 * produced byte identical files for all six shots — so on one machine it works.
 *
 * It was still rejected. Byte equality would additionally require `libaom` to
 * emit the same bytes on a CI runner as on a maintainer's laptop, and an encoder
 * is free to take a different SIMD path on a different CPU and still be correct.
 * The failure that would produce is the worst kind: a gate that is red for
 * everybody, for no reason anyone can see, whose obvious fix is to delete it.
 * Comparing decoded pixels costs one extra decode and cannot fail that way.
 *
 * Both sides are decoded **from AVIF**, never PNG against AVIF. The encode is
 * lossy at `quality: 55`, so a raw screenshot compared against a stored image
 * would differ everywhere by a little and the tolerance would have to be raised
 * until it caught nothing.
 */
import * as fs from 'node:fs/promises';
import * as path from 'node:path';
import sharp from 'sharp';
import { artifactPath } from '../manifest';

/**
 * How far one channel of one pixel may differ before the pixel is counted.
 *
 * Not zero, because both sides survived a lossy encode and a decoder is not
 * required to be bit exact. Well below the smallest difference that means
 * anything: the border of a button against its background is over 40 levels
 * apart, and text against the page is over 200.
 */
const channelTolerance = 12;

/**
 * How many pixels may differ before the shot is called stale.
 *
 * A count rather than a ratio, for the reason the visual regression suite gives
 * for the same choice: a ratio is nearly free on `owner-view`, which is ten
 * megapixels, and unusably tight on `field-open`, which is a hundredth of that.
 *
 * The headroom is measured, not guessed — see the calibration in
 * `docs/testing/acceptance-tests.md`. A one word label change is thousands of
 * pixels at this resolution, so nothing that a reader would notice fits
 * underneath.
 *
 * The count is a trigger, not a measure of how much moved: both sides are
 * whole-image AVIF encodes, so a change in one region shifts the encoder's bit
 * allocation and perturbs unrelated ones below the visible threshold. The same
 * page explains how to read a diff by region instead.
 */
const maxDifferingPixels = 60;

export interface ComparisonResult {
    readonly differingPixels: number;
    /** Written only on failure, so a person can look at what changed. */
    readonly reportDirectory?: string;
}

async function decode(image: Buffer): Promise<{ data: Buffer; width: number; height: number; channels: number }> {
    const { data, info } = await sharp(image).raw().toBuffer({ resolveWithObject: true });

    return { data, width: info.width, height: info.height, channels: info.channels };
}

/**
 * Fails unless the new shot matches the committed one.
 *
 * On failure it writes both images and a diff beside the other test artifacts,
 * because "4212 pixels differ" tells nobody whether a restyle landed or a label
 * moved. The diff marks a differing pixel red over a dimmed copy of the
 * committed image, which is the convention Playwright's own report uses.
 *
 * @param committedFile Absolute path of the image the repository carries.
 * @param taken The same shot, encoded exactly as the generator encodes it.
 * @param name The shot name, used for the report directory.
 */
export async function assertShotIsCurrent(committedFile: string, taken: Buffer, name: string): Promise<ComparisonResult> {
    let committed: Buffer;
    try {
        committed = await fs.readFile(committedFile);
    } catch {
        throw new Error(
            `The shot "${name}" has no committed image at "${committedFile}".\n`
            + 'Generate it with "Build/Scripts/runTests.sh -s screenshotDocumentation" and commit the result.',
        );
    }

    const [before, after] = await Promise.all([decode(committed), decode(taken)]);

    if (before.width !== after.width || before.height !== after.height) {
        throw new Error(
            `The shot "${name}" changed size: the committed image is ${before.width}x${before.height}, `
            + `the surface now photographs as ${after.width}x${after.height}.\n`
            + 'A size change is always a real change. Regenerate with '
            + '"Build/Scripts/runTests.sh -s screenshotDocumentation" once you have looked at why.',
        );
    }

    const channels = Math.min(before.channels, after.channels);
    const diff = Buffer.alloc(before.width * before.height * 3);
    let differingPixels = 0;

    for (let pixel = 0; pixel < before.width * before.height; pixel++) {
        const at = pixel * before.channels;
        let delta = 0;
        for (let channel = 0; channel < channels; channel++) {
            delta = Math.max(delta, Math.abs(before.data[at + channel] - after.data[at + channel]));
        }

        if (delta > channelTolerance) {
            differingPixels++;
            diff[pixel * 3] = 255;
            diff[pixel * 3 + 1] = 0;
            diff[pixel * 3 + 2] = 0;
        } else {
            // A dimmed copy of the committed pixel, so the red reads as a
            // location on the surface rather than as confetti.
            const grey = Math.round((before.data[at] + before.data[at + 1] + before.data[at + 2]) / 3);
            const dimmed = 200 + Math.round(grey * 0.2);
            diff[pixel * 3] = dimmed;
            diff[pixel * 3 + 1] = dimmed;
            diff[pixel * 3 + 2] = dimmed;
        }
    }

    if (differingPixels <= maxDifferingPixels) {
        return { differingPixels };
    }

    const reportDirectory = artifactPath(path.join('screenshot-check-reports', name));
    await fs.mkdir(reportDirectory, { recursive: true });
    const raw = { width: before.width, height: before.height, channels: 3 as const };
    await Promise.all([
        sharp(committed).png().toFile(path.join(reportDirectory, 'committed.png')),
        sharp(taken).png().toFile(path.join(reportDirectory, 'taken.png')),
        sharp(diff, { raw }).png().toFile(path.join(reportDirectory, 'diff.png')),
    ]);

    throw new Error(
        `The shot "${name}" no longer matches what the surface looks like: ${differingPixels} pixels differ, `
        + `${maxDifferingPixels} are tolerated.\n`
        + `Look at the three images in "${reportDirectory}" before doing anything else.\n`
        + '  - The change was intended: regenerate with '
        + '"Build/Scripts/runTests.sh -s screenshotDocumentation" and commit the images.\n'
        + '  - The change was not intended: it is a defect in the surface, and the manual is the '
        + 'only thing that noticed.',
    );
}
