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
        }
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
        }
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
