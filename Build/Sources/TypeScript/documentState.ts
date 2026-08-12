/**
 * The one piece of state the page level stylesheet needs: whether the frontend
 * editing module was executed at all.
 *
 * A stylesheet loaded through "f:asset.css" applies whether or not the module
 * behind "f:asset.module" ran — the browser may have failed to resolve the
 * import map entry, or the module may have thrown. Gating every rule on a class
 * the module sets keeps the page unstyled in that case instead of showing edit
 * affordances that nothing responds to.
 */
export const assetsLoadedClass = 'frontend-edit-loaded';

/**
 * Marks the document as carrying a working frontend editing module.
 *
 * @param root the element the stylesheet is scoped to, normally "documentElement"
 */
export function markAssetsLoaded(root: Element): void {
    root.classList.add(assetsLoadedClass);
}
