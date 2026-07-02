# Stripe Module - Resources Directory

This directory contains the development source files and build configuration for the Stripe Module, inspired by the PayPal module structure.

## Directory Structure

```
resources/
├── build/              # Development source files
│   └── js/            # JavaScript source files
│       ├── app.js     # Main entry point
│       └── controllers/
├── esbuild/           # Esbuild configuration files
│   ├── config.js      # Main esbuild configuration
│   └── aliases.json   # Build task aliases
├── build.js           # Main build script
└── README.md          # This file
```

## Build System

The build system uses **esbuild** for fast, modern JavaScript bundling and minification.

### Build Modes

#### Production Build
Outputs minified, optimized bundles to `assets/js/`:

```bash
npm run build:prod
# or
node resources/build.js production
```

**Outputs:**
- `assets/js/stripe-frontend.min.js` - Minified frontend bundle
- `assets/js/stripe-admin.min.js` - Minified admin bundle

#### Development Build
Outputs non-minified bundles with inline sourcemaps:

```bash
npm run build:dev
# or
node resources/build.js development
```

**Outputs:**
- `assets/js/stripe-frontend.js` - Development frontend bundle
- `assets/js/controllers/*.js` - Individual controller files for debugging

#### Watch Mode
Automatically rebuilds on file changes:

```bash
npm run watch
# or
node resources/build.js watch
```

### Configuration

Build configurations are located in `resources/esbuild/config.js` and include:

- **Production**: Minified, optimized bundles
- **Development**: Non-minified bundles with verbose sourcemaps
- **Watch**: Auto-rebuild on file changes

### Adding New Source Files

1. Add your JavaScript files to `resources/build/js/`
2. Import them in `resources/build/js/app.js` if needed
3. Update `resources/esbuild/config.js` if you need separate bundles
4. Run the appropriate build command

### Comparison with PayPal Module

| PayPal (Grunt) | Stripe (Esbuild) |
|----------------|------------------|
| `resources/Gruntfile.js` | `resources/build.js` |
| `resources/grunt/*.js` | `resources/esbuild/config.js` |
| `resources/grunt/aliases.json` | `resources/esbuild/aliases.json` |
| `grunt production` | `npm run build:prod` |
| `grunt development` | `npm run build:dev` |
| `grunt watch` | `npm run watch` |

### Benefits of Esbuild

- **Fast**: 10-100x faster than traditional bundlers
- **Modern**: ES6+ support out of the box
- **Simple**: Minimal configuration needed
- **Tree-shaking**: Removes unused code automatically
- **Sourcemaps**: Full debugging support
