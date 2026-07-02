/**
 * Stripe Module — shared debug logger utility.
 *
 * Phase 5: build-strip + runtime wrapper (defense in depth).
 *
 * SETTLED MECHANISM
 * -----------------
 * 1. Production esbuild adds `pure: ['console.log', 'console.info',
 *    'console.debug', 'console.warn', 'console.trace']` so stray literal
 *    console.* calls in controller source files are stripped from the
 *    minified bundle (their return value is unused — a "pure" call).
 *    `console.error` is NOT in the pure list and is NEVER stripped.
 *
 * 2. The `createDebugLogger` wrapper below routes intentional diagnostic
 *    logs through `consoleRef.log(...)` where `consoleRef` is an aliased
 *    reference captured at module init (`const consoleRef = globalThis.console`).
 *    Because the call site is `consoleRef.log(...)` — NOT the literal string
 *    `console.log(...)` — esbuild's pure pass cannot statically match it and
 *    will NOT strip the call. The runtime guard (enabled flag) decides whether
 *    it fires, giving live opt-in diagnostics without redeploying.
 *
 * 3. Genuine `console.error(...)` calls remain as direct literals in callers.
 *    They are unconditional and always present in every build.
 *
 * USAGE
 * -----
 * In a Stimulus controller that registers a `stripeDebug` Boolean value:
 *
 *   import { createDebugLogger } from '../debug.js'
 *   // ...
 *   connect() {
 *     this._debug = createDebugLogger(() => this.stripeDebugValue)
 *     this._debug('controller connected', { key: this.publishableKeyValue })
 *   }
 */

// Alias console so esbuild `pure` cannot statically match the call site.
// IMPORTANT: keep this as `globalThis.console` (NOT the literal `console`)
// so that the minifier leaves the call in place for the runtime flag to work.
const consoleRef = globalThis.console

/**
 * Factory that returns a debug log function gated by the supplied flag getter.
 *
 * @param {() => boolean} isEnabled - Returns true when debug output is wanted.
 * @returns {(...args: unknown[]) => void}
 */
export function createDebugLogger(isEnabled) {
  return function debug(...args) {
    if (!isEnabled()) {
      return
    }
    // Use aliased reference — NOT a literal `console.log(...)` — so the
    // production esbuild `pure` pass cannot strip this call.
    consoleRef.log(...args)
  }
}
