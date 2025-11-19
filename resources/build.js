#!/usr/bin/env node

/**
 * Esbuild Build Script for Stripe Module
 * Inspired by PayPal module's Grunt structure
 *
 * Usage:
 *   node resources/build.js                    # Run production build
 *   node resources/build.js development        # Run development build
 *   node resources/build.js watch              # Run watch mode
 */

const esbuild = require('esbuild');
const path = require('path');
const fs = require('fs');
const { productionConfig, developmentConfig, PATHS } = require('./esbuild/config');

// Parse command line arguments
const args = process.argv.slice(2);
const mode = args[0] || 'production';

// Color codes for terminal output
const colors = {
    reset: '\x1b[0m',
    green: '\x1b[32m',
    yellow: '\x1b[33m',
    blue: '\x1b[34m',
    red: '\x1b[31m'
};

/**
 * Log with color
 */
function log(message, color = 'reset') {
    console.log(`${colors[color]}${message}${colors.reset}`);
}

/**
 * Ensure output directories exist
 */
function ensureDirectories() {
    const dirs = [
        path.join(PATHS.assets, 'js'),
        path.join(PATHS.assets, 'js/controllers')
    ];

    dirs.forEach(dir => {
        if (!fs.existsSync(dir)) {
            fs.mkdirSync(dir, { recursive: true });
            log(`Created directory: ${dir}`, 'blue');
        }
    });
}

/**
 * Build with esbuild configuration
 */
async function build(config, label) {
    try {
        log(`\nBuilding ${label}...`, 'blue');
        const result = await esbuild.build(config);

        if (result.errors.length > 0) {
            log(`✗ Build failed for ${label}`, 'red');
            console.error(result.errors);
            return false;
        }

        log(`✓ Built ${label} successfully`, 'green');
        return true;
    } catch (error) {
        log(`✗ Error building ${label}:`, 'red');
        console.error(error);
        return false;
    }
}

/**
 * Watch mode with esbuild
 */
async function watch(config, label) {
    try {
        const ctx = await esbuild.context(config);
        await ctx.watch();
        log(`👀 Watching ${label} for changes...`, 'yellow');
        return ctx;
    } catch (error) {
        log(`✗ Error setting up watch for ${label}:`, 'red');
        console.error(error);
        return null;
    }
}

/**
 * Production build
 */
async function buildProduction() {
    log('\n🚀 Starting PRODUCTION build...', 'green');
    ensureDirectories();

    const results = await Promise.all([
        build(productionConfig.frontend, 'Frontend (Production)'),
        build(productionConfig.admin, 'Admin (Production)')
    ]);

    if (results.every(r => r === true)) {
        log('\n✓ All production builds completed successfully!', 'green');
        return true;
    } else {
        log('\n✗ Some production builds failed!', 'red');
        return false;
    }
}

/**
 * Development build
 */
async function buildDevelopment() {
    log('\n🔧 Starting DEVELOPMENT build...', 'yellow');
    ensureDirectories();

    const results = await Promise.all([
        build(developmentConfig.frontend, 'Frontend (Development)'),
        build(developmentConfig.controllers, 'Controllers (Development)')
    ]);

    if (results.every(r => r === true)) {
        log('\n✓ All development builds completed successfully!', 'green');
        return true;
    } else {
        log('\n✗ Some development builds failed!', 'red');
        return false;
    }
}

/**
 * Watch mode
 */
async function startWatch() {
    log('\n👀 Starting WATCH mode...', 'yellow');
    ensureDirectories();

    const contexts = await Promise.all([
        watch(developmentConfig.frontend, 'Frontend'),
        watch(developmentConfig.controllers, 'Controllers')
    ]);

    if (contexts.every(ctx => ctx !== null)) {
        log('\n✓ Watch mode started successfully!', 'green');
        log('Press Ctrl+C to stop watching', 'yellow');

        // Keep the process running
        process.on('SIGINT', async () => {
            log('\n\nStopping watch mode...', 'yellow');
            await Promise.all(contexts.map(ctx => ctx.dispose()));
            log('✓ Watch mode stopped', 'green');
            process.exit(0);
        });
    } else {
        log('\n✗ Failed to start watch mode!', 'red');
        process.exit(1);
    }
}

/**
 * Main execution
 */
async function main() {
    log('═══════════════════════════════════════════', 'blue');
    log('  Stripe Module - Esbuild Build System', 'blue');
    log('═══════════════════════════════════════════', 'blue');

    let success = false;

    switch (mode) {
        case 'production':
        case 'prod':
            success = await buildProduction();
            break;

        case 'development':
        case 'dev':
            success = await buildDevelopment();
            break;

        case 'watch':
            await startWatch();
            return; // Watch mode keeps running

        default:
            log(`\n✗ Unknown mode: ${mode}`, 'red');
            log('\nAvailable modes:', 'yellow');
            log('  - production (default)', 'yellow');
            log('  - development', 'yellow');
            log('  - watch', 'yellow');
            process.exit(1);
    }

    process.exit(success ? 0 : 1);
}

// Run the build
main().catch(error => {
    log('\n✗ Unexpected error:', 'red');
    console.error(error);
    process.exit(1);
});
