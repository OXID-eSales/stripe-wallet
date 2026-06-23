/**
 * Esbuild Configuration for Stripe Module
 * Similar to PayPal's Grunt configuration structure
 */

const path = require('path');

// Base paths
const PATHS = {
    build: path.resolve(__dirname, '../build'),
    assets: path.resolve(__dirname, '../../assets')
};

/**
 * Production build configuration
 * Outputs minified bundles to assets/js/
 *
 * Phase 5 — build-strip mechanism:
 * `pure` marks these console methods as side-effect-free. When `minify: true`,
 * esbuild removes calls whose return value is unused — which covers every
 * direct `console.log(...)` call a developer might leave in controller source.
 *
 * Intentional `debug(...)` calls use `consoleRef.log(...)` (aliased reference)
 * inside debug.js, so esbuild cannot statically match them — they survive in
 * the bundle and the runtime flag controls whether they fire.
 *
 * `console.error` is deliberately ABSENT from this list so genuine failure
 * paths are always present in the production bundle.
 *
 * NOTE: Do NOT use `drop: ['console']` — it removes `console.error` too.
 */
const PRODUCTION_PURE_CONSOLE = [
    'console.log',
    'console.info',
    'console.debug',
    'console.warn',
    'console.trace'
];

/**
 * Production build configuration
 * Outputs minified bundles to assets/js/
 */
const productionConfig = {
    // Main frontend bundle
    frontend: {
        entryPoints: [path.join(PATHS.build, 'js/app.js')],
        bundle: true,
        minify: true,
        sourcemap: true,
        outfile: path.join(PATHS.assets, 'js/stripe-frontend.min.js'),
        format: 'iife',
        platform: 'browser',
        target: ['es2017'],
        define: {
            'process.env.NODE_ENV': '"production"'
        },
        pure: PRODUCTION_PURE_CONSOLE
    },

    // Admin bundle (if needed)
    admin: {
        entryPoints: [path.join(PATHS.build, 'js/app.js')],
        bundle: true,
        minify: true,
        sourcemap: true,
        outfile: path.join(PATHS.assets, 'js/stripe-admin.min.js'),
        format: 'iife',
        platform: 'browser',
        target: ['es2017'],
        define: {
            'process.env.NODE_ENV': '"production"'
        },
        pure: PRODUCTION_PURE_CONSOLE
    }
};

/**
 * Development build configuration
 * Outputs non-minified bundles with more verbose sourcemaps
 */
const developmentConfig = {
    // Main frontend bundle for development
    frontend: {
        entryPoints: [path.join(PATHS.build, 'js/app.js')],
        bundle: true,
        minify: false,
        sourcemap: 'inline',
        outfile: path.join(PATHS.assets, 'js/stripe-frontend.js'),
        format: 'iife',
        platform: 'browser',
        target: ['es2017'],
        define: {
            'process.env.NODE_ENV': '"development"'
        }
    },

    // Individual controllers for development (unbundled)
    controllers: {
        entryPoints: [
            path.join(PATHS.build, 'js/controllers/stripe_order_controller.js')
        ],
        bundle: false,
        minify: false,
        sourcemap: 'inline',
        outdir: path.join(PATHS.assets, 'js/controllers'),
        format: 'esm',
        platform: 'browser',
        target: ['es2017']
    }
};

module.exports = {
    PATHS,
    productionConfig,
    developmentConfig
};
