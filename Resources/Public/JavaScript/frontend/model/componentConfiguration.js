/* Generated from Build/Sources/TypeScript — do not edit. */
import { html, nothing } from "lit";
import { unsafeHTML } from "lit/directives/unsafe-html.js";
const emptyConfiguration = { icons: {}, classes: {} };
function stringMap(value) {
  if (value === null || typeof value !== "object") {
    return {};
  }
  const entries = {};
  for (const [key, entry] of Object.entries(value)) {
    if (typeof entry === "string") {
      entries[key] = entry;
    }
  }
  return entries;
}
function parseComponentConfiguration(value) {
  if (value === null || typeof value !== "object") {
    return emptyConfiguration;
  }
  const source = value;
  return {
    icons: stringMap(source.icons),
    classes: stringMap(source.classes)
  };
}
function icon(configuration, name) {
  const markup = configuration.icons[name];
  if (markup === void 0 || markup === "") {
    return nothing;
  }
  return html`<span class="frontend-edit-icon" aria-hidden="true">${unsafeHTML(markup)}</span>`;
}
function classesFor(configuration, type, ...own) {
  const extra = configuration.classes[type] ?? "";
  return [...own, extra].filter((entry) => entry !== "").join(" ");
}
export {
  classesFor,
  emptyConfiguration,
  icon,
  parseComponentConfiguration
};
