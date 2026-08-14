/**
 * The WCAG contrast ratio, computed in the test process rather than in the page.
 *
 * The browser is asked only what it *painted* — `getComputedStyle` values, which
 * it cannot get wrong — and the arithmetic happens here, where it is reviewable
 * in one place and where a mistake in it cannot be mistaken for a finding about
 * the surface. The same formula exists once more, in PHP, in
 * `Tests/Unit/Styling/ControlBorderContrastTest`; that is a deliberate second
 * copy, because the two suites measure different things (the shipped defaults
 * against a live page) and sharing code between a PHP unit test and a Playwright
 * spec would cost more than the twenty lines it saved.
 */

export interface Rgb {
    readonly red: number;
    readonly green: number;
    readonly blue: number;
}

/**
 * Parses what `getComputedStyle` answers for a colour.
 *
 * A **translucent** colour is rejected rather than approximated. Blending it
 * would need the exact stack of things behind it, and a border at 50% alpha does
 * not have "a" contrast ratio — it has one per backdrop. Nothing on this surface
 * draws one today, so the honest response to encountering one is to stop and
 * make somebody decide, not to return a number that looks authoritative.
 */
export function parseComputedColour(value: string): Rgb {
    const numbers = value.match(/[\d.]+/g);
    if (numbers === null || numbers.length < 3) {
        throw new Error(`Not a computed colour: "${value}".`);
    }
    if (numbers.length > 3 && Number.parseFloat(numbers[3]) < 1) {
        throw new Error(
            `"${value}" is translucent, and a translucent border has one contrast ratio per backdrop. ` +
                'Decide what it should be measured against before asserting on it.',
        );
    }

    return {
        red: Number.parseFloat(numbers[0]),
        green: Number.parseFloat(numbers[1]),
        blue: Number.parseFloat(numbers[2]),
    };
}

/** Relative luminance per the WCAG definition: channels linearised, then weighted. */
export function relativeLuminance(colour: Rgb): number {
    const channels = [colour.red, colour.green, colour.blue].map((value: number): number => {
        const scaled = value / 255;

        return scaled <= 0.04045 ? scaled / 12.92 : ((scaled + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

/** The contrast ratio of two computed colour values, between 1 and 21. */
export function contrastRatio(first: string, second: string): number {
    const a = relativeLuminance(parseComputedColour(first));
    const b = relativeLuminance(parseComputedColour(second));

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}
