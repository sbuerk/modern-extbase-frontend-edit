/**
 * The configuration the server resolved, and the two things it decides.
 *
 * This module replaces `icon/icons.ts`, which held thirteen glyphs as compiled
 * `lit` templates. That was the right answer while the surface lived in a shadow
 * root and there was nothing else it could be — but it made an icon
 * unreplaceable for anybody who did not own this repository: changing one meant
 * editing TypeScript and rebuilding the assets of somebody else's extension.
 *
 * Now the server resolves an icon identifier through TYPO3's `IconRegistry` and
 * hands the **markup** over in `data-config`, so a project repoints an action at
 * a different identifier in `$GLOBALS['TYPO3_CONF_VARS']`, or re-registers the
 * identifier itself, and neither touches a line of JavaScript.
 *
 * ## The markup is inserted unescaped, and that is safe here
 *
 * `unsafeHTML` is exactly as unsafe as its name says, so the reasoning has to be
 * explicit rather than assumed:
 *
 * - The markup never comes from a user. It comes from an SVG file on disk,
 *   named by an identifier registered in PHP by an extension the installation
 *   installed.
 * - It has already been sanitised. `SvgIconProvider` inline markup goes through
 *   `SvgDocumentFactory::fromStringAndSanitize()`, which is core's own SVG
 *   sanitiser and the same one the backend relies on.
 * - It arrives in the same document as everything else the server rendered. A
 *   server that could inject script here could inject it directly.
 *
 * What would *not* be safe is accepting markup from anywhere else - a request
 * parameter, a record field, a third payload. If a future change makes icon
 * markup travel from somewhere a visitor can influence, this decision has to be
 * revisited rather than inherited.
 */
import { html, nothing } from 'lit';
import type { TemplateResult } from 'lit';
import { unsafeHTML } from 'lit/directives/unsafe-html.js';

/**
 * The actions the surface draws. These names are the contract with
 * `ComponentConfigurationFactory::DEFAULT_ICONS` and have to agree with it.
 */
export type IconName =
    | 'edit'
    | 'editRecord'
    | 'apply'
    | 'cancel'
    | 'add'
    | 'remove'
    | 'chooseImage'
    | 'moveUp'
    | 'moveDown'
    | 'moveToTop'
    | 'moveToBottom'
    | 'hide'
    | 'show';

/**
 * The kinds of element a project may add classes to. Agrees with
 * `ComponentConfigurationFactory::DEFAULT_CLASSES`.
 */
export type ElementType =
    | 'record'
    | 'child'
    | 'field'
    | 'label'
    | 'value'
    | 'control'
    | 'button'
    | 'buttonPrimary'
    | 'buttonDanger'
    | 'buttonIconOnly'
    | 'filePicker'
    | 'errors'
    | 'state';

export interface ComponentConfiguration {
    readonly icons: Readonly<Partial<Record<IconName, string>>>;
    readonly classes: Readonly<Partial<Record<ElementType, string>>>;
}

/**
 * The configuration of a surface whose `data-config` was missing or unusable.
 *
 * Empty rather than a built-in fallback set, and that is a decision: a surface
 * with no glyphs is complete and usable, because every button keeps its
 * translated label. A surface with *guessed* glyphs would be a second icon set
 * living in the JavaScript, which is the thing this module exists to remove.
 */
export const emptyConfiguration: ComponentConfiguration = { icons: {}, classes: {} };

function stringMap(value: unknown): Record<string, string> {
    if (value === null || typeof value !== 'object') {
        return {};
    }
    const entries: Record<string, string> = {};
    for (const [key, entry] of Object.entries(value as Record<string, unknown>)) {
        if (typeof entry === 'string') {
            entries[key] = entry;
        }
    }

    return entries;
}

/**
 * Reads what the server put in `data-config`.
 *
 * Tolerant on purpose, and in the same direction as everything else that parses
 * an attribute here: anything unusable degrades to "not configured" rather than
 * throwing. A surface that refused to render because one class name was a number
 * would be a worse outcome than one that draws a button without an extra class.
 */
export function parseComponentConfiguration(value: unknown): ComponentConfiguration {
    if (value === null || typeof value !== 'object') {
        return emptyConfiguration;
    }
    const source = value as { icons?: unknown; classes?: unknown };

    return {
        icons: stringMap(source.icons),
        classes: stringMap(source.classes),
    };
}

/**
 * One icon, ready to be placed inside a button.
 *
 * Returns nothing at all for an action the configuration does not carry, which
 * is what makes a missing or mistyped identifier cost one glyph rather than the
 * surface.
 */
export function icon(configuration: ComponentConfiguration, name: IconName): TemplateResult | typeof nothing {
    const markup = configuration.icons[name];
    if (markup === undefined || markup === '') {
        return nothing;
    }

    /*
     * `unsafeHTML`, not `unsafeSVG`. The markup the server sends is a complete
     * `<svg>` element being inserted into an HTML context; `unsafeSVG` parses
     * its argument as the *children* of an `<svg>`, which is the wrong namespace
     * for this and produces an element the browser does not render.
     *
     * The wrapping span carries the class and the `aria-hidden`, so neither has
     * to be present in the file on disk - which means a project can register any
     * SVG it likes as a replacement without knowing this extension's
     * conventions.
     */
    return html`<span class="frontend-edit-icon" aria-hidden="true">${unsafeHTML(markup)}</span>`;
}

/**
 * The additional classes for one kind of element, as a class attribute value.
 *
 * The extension's own `frontend-edit-*` class is always first and is never
 * configurable: it is what the stylesheet and the acceptance suite address, so
 * letting configuration remove it would let an installation break the surface
 * and its own tests from a settings file.
 */
export function classesFor(
    configuration: ComponentConfiguration,
    type: ElementType,
    ...own: readonly string[]
): string {
    const extra = configuration.classes[type] ?? '';

    return [...own, extra].filter((entry: string): boolean => entry !== '').join(' ');
}
