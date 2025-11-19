# Stripe Module - Development Mode Guide

## Overview

The Stripe module supports **Development Mode** where JavaScript files are loaded separately (unbundled) for easier debugging.

### Production vs Development

| Mode | Files Loaded | Minified | Source Maps | Cache Busting |
|------|--------------|----------|-------------|---------------|
| **Production** | `stripe.min.js` (bundled) | ✅ Yes | ✅ Yes | Version-based |
| **Development** | `stripe.dev.js` + controllers | ❌ No | ✅ Yes | Timestamp-based |

## Enabling Development Mode

### Method 1: Auto-Detection (Recommended)

Development mode is **automatically enabled** if:

- Domain contains: `.local`, `.dev`, `.test`, or `oxiddev.de`
- OXID debug mode is enabled (`iDebug > 0`)

Your current domain (`bartosz.oxiddev.de`) will automatically use dev mode! ✅

### Method 2: Environment Variable

Add to `.env`:
```bash
STRIPE_DEV_MODE=1
```

### Method 3: OXID Admin

1. Go to: **Extensions → Modules → Stripe Payment Gateway**
2. Find: **Development Mode** setting
3. Enable checkbox
4. Save

### Method 4: Manual Config

Edit `config.inc.php`:
```php
$this->sStripeDevMode = true;
```

## Setup Development Environment

### 1. Install Dependencies

```bash
cd /home/gaad/PhpStormProjects/OXID/Stripe/stripe-wallet/source/extensions/stripe

# Install packages
npm install
```

This installs:
- `@hotwired/stimulus` - Stimulus.js framework
- `esbuild` - Fast bundler
- `concurrently` - Run multiple watchers

### 2. Build Development Files

```bash
# Build once
npm run build:dev
```

This creates:
```
out/js/
├── stripe.dev.js           # Development bundle with debug
├── stripe.dev.js.map       # Source map
├── controllers/
│   ├── buy_now_controller.js      # Individual controller
│   └── buy_now_controller.js.map  # Source map
└── vendors/
    └── stimulus.js         # Stimulus library (unbundled)
```

### 3. Watch Mode (Recommended)

```bash
# Auto-rebuild on file changes
npm run watch
```

This runs two watchers concurrently:
1. **App watcher** - Rebuilds `stripe.dev.js` on changes
2. **Controllers watcher** - Rebuilds individual controllers

Leave this running while developing!

## File Structure

### Production Build

```javascript
// Single bundled file
out/js/stripe.min.js (46KB minified)
└── Contains:
    ├── Stimulus.js
    ├── app.js
    └── All controllers
```

### Development Build

```javascript
// Separate files for debugging
out/js/
├── stripe.dev.js                  // Main app with Stimulus
│   └── Source: assets/src/js/app.js
├── controllers/
│   └── buy_now_controller.js      // Individual controller
│       └── Source: assets/src/js/controllers/buy_now_controller.js
└── vendors/
    └── stimulus.js                // Stimulus (not bundled)
```

## Development Workflow

### 1. Start Watch Mode

```bash
npm run watch
```

Terminal output:
```
[0] Watching assets/src/js/app.js...
[1] Watching assets/src/js/controllers/*.js...
```

### 2. Edit Controller

Edit: `assets/src/js/controllers/buy_now_controller.js`

```javascript
export default class extends Controller {
  submit(event) {
    console.log('🐛 Debug: Button clicked!')  // ← Add debug logs
    // ... rest of code
  }
}
```

### 3. Auto-Rebuild

Watch mode detects changes and rebuilds:
```
[1] ✨ Built in 15ms
```

### 4. Refresh Browser

Hard refresh (Ctrl+Shift+R) to load new files.

### 5. Debug in DevTools

**Sources Tab:**
- Find: `webpack://` or `stripe.dev.js`
- See original source files with line numbers
- Set breakpoints
- Step through code

**Console:**
```javascript
🔧 Stripe Module: Development Mode Active
📝 Source maps enabled for debugging
🔍 Individual controller files loaded
✅ Registered controller: buy-now
🚀 Stripe Module: All controllers loaded and ready
```

## Debugging Features

### Console Helpers

In development mode, these are available:

```javascript
// Check Stimulus instance
window.Stimulus

// Get all registered controllers
window.Stimulus.router.modulesByIdentifier

// Access debug utilities
window.StripeDebug.stimulus
```

### Debug Mode Flag

Check if dev mode is active:
```javascript
if (window.STRIPE_DEV_MODE) {
  console.log('Running in development mode')
}
```

### Source Maps

Source maps are included in both modes, but dev mode has:
- **Non-minified code** - Easier to read
- **Preserved variable names** - Original names, not mangled
- **Comments preserved** - Your code comments remain

## Adding New Controllers

### 1. Create Controller File

```bash
touch assets/src/js/controllers/my_new_controller.js
```

### 2. Write Controller

```javascript
import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
  connect() {
    console.log("My controller connected!")
  }
}
```

### 3. Register in app.js

Edit `assets/src/js/app.js`:
```javascript
import MyNewController from "./controllers/my_new_controller"

Stimulus.register("my-new", MyNewController)
```

### 4. Rebuild

If watch mode is running, it auto-rebuilds!

Otherwise:
```bash
npm run build:dev
```

### 5. Use in Template

```twig
<div data-controller="my-new">
  <!-- Your HTML -->
</div>
```

## Build Commands

### Development

```bash
# Build dev files once
npm run build:dev

# Watch and auto-rebuild
npm run watch

# Shorthand for build:dev
npm run dev
```

### Production

```bash
# Build minified for production
npm run build

# Or explicitly
npm run build:prod
```

## Troubleshooting

### Dev Mode Not Activating

**Check 1:** Verify domain detection
```php
// In PHP
var_dump($_SERVER['SERVER_NAME']);
// Should contain: .local, .dev, .test, or oxiddev.de
```

**Check 2:** Check console
```javascript
// Should see:
🔧 Stripe Module: Development Mode Active
```

If not visible, check:
- Browser cache (Ctrl+Shift+R)
- ViewConfig extension registered in metadata.php
- Module activated

### Source Maps Not Working

**Problem:** Can't see original source in DevTools

**Solution:**
1. Enable source maps in DevTools:
   - Settings → Enable JavaScript source maps
2. Rebuild:
   ```bash
   npm run build:dev
   ```
3. Hard refresh browser

### Watch Not Detecting Changes

**Problem:** Files change but no rebuild

**Solution:**
1. Check watch is running:
   ```bash
   npm run watch
   ```
2. Check file is in watched path:
   - `assets/src/js/**/*.js`
3. Try restarting watch

### Controllers Not Loading

**Problem:** Controller doesn't connect

**Check console for:**
```
❌ Failed to load controller buy-now: [error]
```

**Solutions:**
1. Check controller export:
   ```javascript
   export default class extends Controller { }  // ✅ Correct
   export class BuyNow extends Controller { }   // ❌ Wrong
   ```
2. Check registration:
   ```javascript
   Stimulus.register("buy-now", BuyNowController)  // ✅
   ```
3. Check HTML:
   ```html
   data-controller="buy-now"  <!-- ✅ Matches registration -->
   ```

## Performance

### Development Mode Impact

| Metric | Production | Development | Difference |
|--------|-----------|-------------|------------|
| **File Size** | 46 KB | ~150 KB | +226% |
| **Files Loaded** | 1 | 3+ | +200% |
| **Load Time** | ~50ms | ~100ms | +100% |
| **Parse Time** | Fast | Slower | Debugging overhead |

**Note:** Use development mode **only** during development!

## Best Practices

### ✅ DO

- Use watch mode during active development
- Enable dev mode on local/dev domains
- Use console.log for debugging
- Set breakpoints in DevTools
- Test with dev mode before production build

### ❌ DON'T

- Enable dev mode in production
- Commit `stripe.dev.js` without `stripe.min.js`
- Skip building for production
- Use dev mode for performance testing

## Switching Modes

### From Development → Production

1. **Build production files:**
   ```bash
   npm run build
   ```

2. **Disable dev mode:**
   - Remove from `.env`: `STRIPE_DEV_MODE=1`
   - Or disable in admin
   - Or change domain to production

3. **Clear cache:**
   ```bash
   php bin/oe-console oe:cache:clear
   ```

4. **Verify:**
   - Check console: Should NOT see 🔧 Dev mode message
   - Check Network tab: Loading `stripe.min.js` not `stripe.dev.js`

### From Production → Development

1. **Build dev files:**
   ```bash
   npm run build:dev
   ```

2. **Enable dev mode:**
   - Set `.env`: `STRIPE_DEV_MODE=1`
   - Or enable in admin

3. **Start watch:**
   ```bash
   npm run watch
   ```

4. **Refresh browser**

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Build JavaScript

on: [push]

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup Node.js
        uses: actions/setup-node@v3
        with:
          node-version: '18'

      - name: Install dependencies
        working-directory: extensions/stripe
        run: npm install

      - name: Build production
        working-directory: extensions/stripe
        run: npm run build

      - name: Verify files
        run: |
          test -f extensions/stripe/out/js/stripe.min.js
          test -f extensions/stripe/out/js/stripe.min.js.map

      - name: Commit built files
        run: |
          git config user.name "GitHub Actions"
          git config user.email "actions@github.com"
          git add extensions/stripe/out/js/
          git commit -m "Build JavaScript" || echo "No changes"
          git push
```

## Related Documentation

- [assets/README.md](assets/README.md) - Detailed asset documentation
- [BUY_NOW_FEATURE.md](docs/one-page-checkout/BUY_NOW_FEATURE.md) - Buy Now feature
- [Stimulus Handbook](https://stimulus.hotwired.dev/) - Stimulus.js docs

---

**Last Updated:** 2025-11-12
**Version:** 1.0.0
