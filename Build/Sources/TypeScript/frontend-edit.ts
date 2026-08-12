/**
 * Entry point of the frontend editing assets.
 *
 * This module is scaffolding, not the feature: it exists so the toolchain is
 * proven end to end — TypeScript source, an internal module bundled into it, an
 * ES module in "Resources/Public/JavaScript/" and a stylesheet keyed on what it
 * does. The edit UI replaces the body of this file in a later change; the entry
 * point itself and its import-map specifier stay.
 *
 * One entry point per import-map specifier: "documentState.ts" is imported here
 * and therefore bundled into this file rather than addressable on its own.
 */
import { markAssetsLoaded } from './documentState.js';

markAssetsLoaded(document.documentElement);

export { assetsLoadedClass, markAssetsLoaded } from './documentState.js';
