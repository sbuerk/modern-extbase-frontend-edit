/**
 * The icon set of the editing surface, as inline SVG.
 *
 * ## Why they are drawn here and not fetched from anywhere
 *
 * Three options were available and two of them are closed:
 *
 * - **An icon font, or an SVG sprite from a CDN.** Refused by the Content
 *   Security Policy this extension declares, which permits the installation's
 *   own origin only. That is deliberate — see
 *   `Documentation/Configuration/ContentSecurityPolicy.rst`.
 * - **TYPO3's own `IconFactory`, through a ViewHelper.** Cannot reach these
 *   buttons. Every action the surface draws is rendered *client side*, in a
 *   shadow root, from JSON the server handed over in an attribute; by the time
 *   a button exists, Fluid has long finished. The icons therefore have to be
 *   shipped in the JavaScript, in the module that renders the button.
 * - **Inline SVG in the template**, which is what this is. It touches no CSP
 *   directive at all — markup is not a fetch — costs no request, and inherits
 *   `currentColor`, so an icon follows whatever colour its button already has,
 *   including the emphasised and destructive variants.
 *
 * ## They are decoration, never the label
 *
 * Every glyph is `aria-hidden` and `focusable="false"`, and every button keeps
 * its translated text — visible in most places, visually hidden in the record
 * toolbars, but always in the accessibility tree and always in `textContent`.
 *
 * That is not only an accessibility position, it is what keeps the suite honest:
 * `Tests/Acceptance/Support/profileEditPage.ts` addresses every button by its
 * accessible name, and `Tests/Acceptance/Frontend/ButtonHierarchy.spec.ts` reads
 * `textContent`. An icon-only button carrying its name in `aria-label` would
 * satisfy the first and silently break the second. A visually hidden `<span>`
 * satisfies both, and survives a stylesheet that fails to load.
 *
 * ## The drawing
 *
 * One 24×24 grid, stroked rather than filled, no `stroke-width` per path — the
 * weight is set once on the `<svg>` so an icon scales with the text around it
 * instead of thickening. They are drawn here by hand rather than copied from a
 * set, because a set would be a dependency, a licence and a build step for ten
 * glyphs of a dozen points each.
 */
import { html, svg } from 'lit';
import type { SVGTemplateResult, TemplateResult } from 'lit';

export type IconName =
    | 'edit'
    | 'editRecord'
    | 'apply'
    | 'cancel'
    | 'add'
    | 'remove'
    | 'moveUp'
    | 'moveDown'
    | 'hide'
    | 'show';

/**
 * The geometry of each icon, without the element that carries it.
 *
 * `apply` is deliberately the same check mark for a field and for a whole
 * record: they are the same act at two scales, and they are never drawn beside
 * each other — a record in edit mode hides the per field buttons.
 */
const shapes: Readonly<Record<IconName, SVGTemplateResult>> = {
    edit: svg`<path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" />`,
    editRecord: svg`
        <path d="M10.5 4.5H5A1.5 1.5 0 0 0 3.5 6v13A1.5 1.5 0 0 0 5 20.5h13a1.5 1.5 0 0 0 1.5-1.5v-5.5" />
        <path d="M17.5 3.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z" />
    `,
    apply: svg`<path d="M4.5 12.5l5 5 10-11" />`,
    cancel: svg`<path d="M6 6l12 12M18 6L6 18" />`,
    add: svg`<path d="M12 5v14M5 12h14" />`,
    remove: svg`
        <path d="M4 6.5h16" />
        <path d="M9.5 6.5v-2a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v2" />
        <path d="M6.5 6.5l.9 12.1a1.5 1.5 0 0 0 1.5 1.4h6.2a1.5 1.5 0 0 0 1.5-1.4l.9-12.1" />
        <path d="M10 10.5v6M14 10.5v6" />
    `,
    moveUp: svg`<path d="M5.5 14.5L12 8l6.5 6.5" />`,
    moveDown: svg`<path d="M5.5 9.5L12 16l6.5-6.5" />`,
    // The button labelled "Hide" performs hiding, so it carries the struck
    // through eye; "Show" performs the opposite and carries the plain one.
    hide: svg`
        <path d="M2.5 12S6.5 5.5 12 5.5 21.5 12 21.5 12 17.5 18.5 12 18.5 2.5 12 2.5 12z" />
        <path d="M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
        <path d="M4 4l16 16" />
    `,
    show: svg`
        <path d="M2.5 12S6.5 5.5 12 5.5 21.5 12 21.5 12 17.5 18.5 12 18.5 2.5 12 2.5 12z" />
        <path d="M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
    `,
};

/**
 * One icon, ready to be placed inside a button.
 *
 * Sized in `em` by the stylesheet rather than here, so the glyph tracks the type
 * size of whatever it sits in.
 */
export const icon = (name: IconName): TemplateResult => html`
    <svg
        class="icon"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
    >
        ${shapes[name]}
    </svg>
`;
