/* Generated from Build/Sources/TypeScript — do not edit. */
import { html, svg } from "lit";
const shapes = {
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
    `
};
const icon = (name) => html`
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
export {
  icon
};
