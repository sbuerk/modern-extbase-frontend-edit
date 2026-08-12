/**
 * Entry point of the frontend editing assets.
 *
 * It does two things and delegates everything else:
 *
 * 1. Registers the custom elements, by importing the modules that define them.
 *    A Fluid template loads this module with `f:asset.module`, and the elements
 *    upgrade whichever markup the server rendered — no initialisation call, no
 *    inline script, nothing to pass in.
 * 2. Marks the document as carrying a working module, which is what the page
 *    level stylesheet gates every rule on.
 *
 * One entry point per import-map specifier: everything imported here is bundled
 * into this file and gets no specifier of its own. `lit` is the exception and
 * is deliberately **not** bundled — it is declared in `EXT:core`'s own module
 * map and resolved through the import map, because a second lit runtime on the
 * page means a second `ReactiveElement` registry and a duplicate
 * `customElements.define()`.
 */
import { markAssetsLoaded } from '@sbuerk/modern-extbase-frontend-edit/frontend/documentState.js';
import '@sbuerk/modern-extbase-frontend-edit/frontend/component/profileEdit.js';

markAssetsLoaded(document.documentElement);

export { assetsLoadedClass, markAssetsLoaded } from '@sbuerk/modern-extbase-frontend-edit/frontend/documentState.js';
