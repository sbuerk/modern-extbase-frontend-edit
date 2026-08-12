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
 * This is the module a template names, not the only one that exists: nothing is
 * bundled, so every module below this directory is emitted as itself and
 * addressed by its own specifier. What makes this one the entry point is that
 * importing it registers the elements and marks the document, which is the
 * whole of what a page needs to do.
 *
 * `lit` is resolved the same way as everything else, out of `EXT:core`'s own
 * module map, and is never shipped here: a second lit runtime on the page means
 * a second `ReactiveElement` registry and a duplicate `customElements.define()`.
 */
import { markAssetsLoaded } from '@sbuerk/modern-extbase-frontend-edit/frontend/documentState.js';
import '@sbuerk/modern-extbase-frontend-edit/frontend/component/profileEdit.js';

markAssetsLoaded(document.documentElement);
