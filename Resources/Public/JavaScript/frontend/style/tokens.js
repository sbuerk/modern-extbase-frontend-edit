/* Generated from Build/Sources/TypeScript — do not edit. */
import { css } from "lit";
const tokens = css`
    :host {
        /*
         * Colour. The light values are the defaults; the dark ones below are a
         * courtesy for a host page that follows the system setting. A host that
         * themes itself by some other means overrides the properties directly,
         * and that beats both of these.
         */
        --frontend-edit-color-accent: #0a7bd4;
        --frontend-edit-color-accent-contrast: #ffffff;
        --frontend-edit-color-danger: #a4141a;
        --frontend-edit-color-border: #c7ccd1;
        --frontend-edit-color-border-strong: #8b9299;
        --frontend-edit-color-surface: #ffffff;
        --frontend-edit-color-surface-sunken: #f2f4f5;
        --frontend-edit-color-muted: #5c6469;

        /*
         * Spacing. A five step scale, because the surface has exactly five
         * distances in it — inside a control, between controls, between a label
         * and its value, between records, and around a collection.
         */
        --frontend-edit-space-xs: 0.25rem;
        --frontend-edit-space-sm: 0.5rem;
        --frontend-edit-space-md: 0.75rem;
        --frontend-edit-space-lg: 1rem;
        --frontend-edit-space-xl: 1.5rem;

        /* Shape. */
        --frontend-edit-border-width: 1px;
        --frontend-edit-radius: 0.25rem;
        --frontend-edit-radius-lg: 0.5rem;

        /*
         * The measure of the surface, and the token most likely to be overridden
         * by a site that gives the plugin a column of its own.
         *
         * Without it the surface is as wide as the content area it sits in, and
         * on a full width page that puts every "Edit" button a thousand pixels
         * away from the value it edits — the field stretches, the actions are
         * pushed to the far edge, and the eye has to travel the whole line to
         * connect the two. This is a form, and a form has a measure.
         */
        --frontend-edit-measure: 48rem;

        /*
         * Type. The family is inherited on purpose — see the docblock. The size
         * is relative to the inherited one for the same reason: a caption is
         * "smaller than the surrounding text", not "14 pixels".
         */
        --frontend-edit-font-family: inherit;
        --frontend-edit-font-size-sm: 0.875em;
        --frontend-edit-label-weight: 600;

        /*
         * Controls. The minimum height is what makes an inline editing surface
         * usable with a finger: a button that is only as tall as its text is a
         * 20 pixel target between two other 20 pixel targets.
         */
        --frontend-edit-control-min-height: 2.25rem;
        --frontend-edit-control-padding-block: 0.375rem;
        --frontend-edit-control-padding-inline: 0.5rem;

        /*
         * Focus. Its own token rather than the accent, because a site may need
         * to raise the contrast of the focus ring alone to satisfy an audit
         * without repainting the whole surface.
         */
        --frontend-edit-focus-color: var(--frontend-edit-color-accent);
        --frontend-edit-focus-width: 2px;
        --frontend-edit-focus-offset: 2px;

        /* The frame the stylesheet draws around the element in the light DOM. */
        --frontend-edit-outline-color: var(--frontend-edit-color-accent);
        --frontend-edit-outline-width: 1px;

        /* State. */
        --frontend-edit-busy-opacity: 0.6;

        /*
         * Motion. One duration, consumed by every transition, so honouring the
         * reduced motion preference is a single declaration below rather than an
         * "!important" sweep over an unknown set of properties.
         */
        --frontend-edit-transition-duration: 120ms;
        --frontend-edit-transition-easing: ease;
    }

    @media (prefers-color-scheme: dark) {
        :host {
            --frontend-edit-color-accent: #4da3e8;
            --frontend-edit-color-accent-contrast: #0b1116;
            --frontend-edit-color-danger: #f0868c;
            --frontend-edit-color-border: #3a4249;
            --frontend-edit-color-border-strong: #5c666e;
            --frontend-edit-color-surface: #1b2126;
            --frontend-edit-color-surface-sunken: #151a1e;
            --frontend-edit-color-muted: #9aa4ac;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        :host {
            --frontend-edit-transition-duration: 0ms;
        }
    }
`;
export {
  tokens
};
