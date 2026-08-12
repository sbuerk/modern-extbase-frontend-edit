/**
 * Decoding the JSON that arrives in a `data-` attribute.
 *
 * The endpoint URLs, the profile document and the labels are rendered by the
 * server into attributes of the custom element, because **no inline script is
 * emitted** — an inline `<script>` would need a CSP nonce, and a nonce makes
 * every page carrying an editable record uncacheable.
 *
 * A malformed attribute answers `null` instead of throwing. It is the only
 * behaviour that keeps a broken deployment readable: the component then does
 * not enhance anything and the server rendered markup stays on the page.
 */
export function readJson(raw: string | null): unknown {
    if (raw === null || raw.trim() === '') {
        return null;
    }
    try {
        return JSON.parse(raw) as unknown;
    } catch {
        return null;
    }
}
