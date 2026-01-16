# Frontend Development Guide

> **Stripe Wallet Module** - Assets Compilation & JavaScript Development

This guide explains how JavaScript assets are compiled, bundled, and used in the Stripe wallet module.

## Table of Contents

- [Directory Structure](#directory-structure)
- [Build Tools](#build-tools)
- [NPM Scripts Reference](#npm-scripts-reference)
- [Compilation Flow](#compilation-flow)
- [Stimulus Framework Integration](#stimulus-framework-integration)
- [Development Workflow](#development-workflow)
- [Adding New Controllers](#adding-new-controllers)
- [Troubleshooting](#troubleshooting)

---

## Directory Structure

```
stripe/
├── assets/
│   └── src/js/                    # SOURCE FILES (edit these)
│       ├── app.js                 # Main entry point (production)
│       ├── app.dev.js            # Development entry point
│       └── controllers/           # Stimulus controllers
│           ├── buy_now_controller.js
│           └── stripe_order_controller.js
│
├── out/                           # COMPILED OUTPUT (auto-generated)
│   └── js/
│       ├── stripe.min.js         # Production bundle (minified, includes Stimulus)
│       ├── stripe.min.js.map     # Source map for production
│       ├── stripe.dev.js         # Development bundle (readable, includes Stimulus)
│       ├── stripe.dev.js.map     # Source map for development
│       └── controllers/          # Individual controllers (dev only)
│           ├── buy_now_controller.js
│           └── stripe_order_controller.js
│
├── node_modules/                 # NPM dependencies
│   ├── @hotwired/stimulus/       # Stimulus framework (bundled by esbuild)
│   ├── esbuild/                  # Build tool
│   └── ...
│
└── package.json                  # NPM configuration & scripts
```

### Directory Purposes

| Directory | Purpose | Version Control |
|-----------|---------|-----------------|
| `assets/src/js/` | Source files you edit | ✅ Commit to Git |
| `out/js/` | Compiled output for browser | ❌ Add to .gitignore |
| `node_modules/` | NPM dependencies | ❌ Add to .gitignore |

---

## Build Tools

### esbuild
- **Fast JavaScript bundler** (written in Go)
- Bundles all imports into single file
- Automatically resolves dependencies from `node_modules/`
- Minifies code for production
- Generates source maps for debugging
- ~10-20ms build time (very fast!)

**How it works:**
```javascript
// In app.js
import { Application } from "@hotwired/stimulus"  // ← esbuild finds this in node_modules

// esbuild automatically:
// 1. Locates node_modules/@hotwired/stimulus/
// 2. Reads the package and resolves entry point
// 3. Bundles Stimulus code into stripe.min.js
// 4. No manual copying needed!
```

### Stimulus.js
- **Frontend framework** by Basecamp
- Organizes JavaScript using controllers
- Connects to HTML via `data-controller` attributes
- Provides Values API for passing data from backend
- **Bundled automatically** - No manual setup required

### Dependencies

```json
{
  "dependencies": {
    "@hotwired/stimulus": "^3.2.2"
  },
  "devDependencies": {
    "concurrently": "^8.2.2",
    "esbuild": "^0.19.12"
  }
}
```

---

## NPM Scripts Reference

### Quick Commands

```bash
# Install dependencies (run once after checkout)
npm install

# Development build (with debug logging)
npm run build:dev

# Production build (minified, optimized)
npm run build:prod

# Watch mode (auto-rebuild on file changes)
npm run watch

# Shortcut for production build
npm run build
```

### Script Details

#### `npm run build:prod`
```bash
esbuild assets/src/js/app.js \
  --bundle \
  --outfile=out/js/stripe.min.js \
  --minify \
  --sourcemap
```

**What it does:**
- ✅ Takes `app.js` as entry point
- ✅ Follows all `import` statements
- ✅ Bundles everything into one file
- ✅ Minifies code (removes whitespace, shortens names)
- ✅ Generates source map for debugging
- ✅ Outputs to `out/js/stripe.min.js` (48KB)

**Use when:** Deploying to production

---

#### `npm run build:dev`
```bash
# Step 1: Bundle main app with debug info
esbuild assets/src/js/app.js \
  --bundle \
  --outfile=out/js/stripe.dev.js \
  --sourcemap \
  --define:process.env.NODE_ENV='"development"'

# Step 2: Compile individual controllers
esbuild assets/src/js/controllers/*.js \
  --outdir=out/js/controllers \
  --sourcemap \
  --format=esm
```

**What it does:**
- ✅ Bundles Stimulus from `node_modules/@hotwired/stimulus` automatically
- ✅ Bundles with `NODE_ENV=development` (enables debug logging)
- ✅ Keeps code readable (no minification)
- ✅ Compiles individual controllers for easier debugging
- ✅ Generates source maps
- ✅ Outputs to `out/js/stripe.dev.js` (90KB)

**Use when:** Developing locally

---

#### `npm run watch`
```bash
concurrently \
  "npm run watch:app" \
  "npm run watch:controllers"
```

**What it does:**
- ✅ Watches `app.js` for changes
- ✅ Watches all `controllers/*.js` for changes
- ✅ Auto-rebuilds on file save
- ✅ Runs both watch processes in parallel

**Use when:** Active development (keeps running in terminal)

---

## Compilation Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                     COMPILATION PROCESS                         │
└─────────────────────────────────────────────────────────────────┘

SOURCE FILES
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  assets/src/js/
    ├── app.js
    └── controllers/
        ├── buy_now_controller.js
        └── stripe_order_controller.js

                    ⬇ npm run build

BUNDLING PROCESS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  1. Read app.js entry point
  2. Follow all import statements
  3. Include Stimulus framework
  4. Bundle all code together
  5. Minify (production) or keep readable (dev)
  6. Generate source maps

                    ⬇

COMPILED OUTPUT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  out/js/
    ├── stripe.min.js (48KB)      ← Production
    └── stripe.dev.js (90KB)      ← Development

                    ⬇

TWIG TEMPLATE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  {% if oViewConf.isStripeDevelopmentMode() %}
    <script src=".../out/js/stripe.dev.js"></script>
  {% else %}
    <script src=".../out/js/stripe.min.js"></script>
  {% endif %}

                    ⬇

BROWSER EXECUTION
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  1. Stimulus.Application.start()
  2. Register controllers
  3. Scan DOM for data-controller attributes
  4. Auto-connect controllers to elements
```

---

## Stimulus Framework Integration

### How Stimulus Works

Stimulus connects JavaScript controllers to HTML elements using `data-` attributes.

### Entry Point: `app.js`

```javascript
import { Application } from "@hotwired/stimulus"

// Import controllers
import BuyNowController from "./controllers/buy_now_controller"
import StripeOrderController from "./controllers/stripe_order_controller"

// Start Stimulus application
window.Stimulus = Application.start()

// Register controllers
Stimulus.register("buy-now", BuyNowController)
Stimulus.register("stripe-order", StripeOrderController)

// Debug mode in development
if (process.env.NODE_ENV === 'development') {
  Stimulus.debug = true
}
```

### Controller Structure

```javascript
import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
  // Define values that come from HTML attributes
  static values = {
    publishableKey: String,
    clientSecret: String
  }

  // Define targets (DOM elements within controller scope)
  static targets = ["errorMessage", "loading"]

  // Called when controller connects to DOM
  connect() {
    console.log('Controller connected')
    console.log('Publishable key:', this.publishableKeyValue)

    // Initialize your logic here
    this.initializeStripe()
  }

  // Called when controller disconnects from DOM
  disconnect() {
    // Cleanup logic here
  }

  // Your custom methods
  async initializeStripe() {
    // Implementation...
  }
}
```

### Using Controllers in Twig Templates

```twig
{# Load the compiled JavaScript bundle #}
<script src="{{ oViewConf.getModuleUrl('oe_payments_stripe_wallet', 'out/js/stripe.min.js') }}"></script>

{# Stimulus auto-connects when it finds this element #}
<div data-controller="stripe-order"
     data-stripe-order-publishable-key-value="{{ stripePublicKey }}"
     data-stripe-order-client-secret-value="{{ clientSecret }}">

    {# Target elements within controller scope #}
    <div data-stripe-order-target="paymentElement"></div>
    <div data-stripe-order-target="errorMessage"></div>
</div>
```

### Stimulus Naming Convention

| HTML Attribute | JavaScript Property |
|----------------|-------------------|
| `data-controller="stripe-order"` | Connects to registered controller |
| `data-stripe-order-publishable-key-value="pk_..."` | `this.publishableKeyValue` |
| `data-stripe-order-client-secret-value="pi_..."` | `this.clientSecretValue` |
| `data-stripe-order-target="errorMessage"` | `this.errorMessageTarget` |

**Pattern:** `data-{controller}-{property}-value` → `this.{property}Value`

---

## Development Workflow

### Initial Setup

```bash
# 1. Clone repository
cd /path/to/stripe-wallet/source/extensions/stripe

# 2. Install dependencies
npm install

# 3. Build for development
npm run build:dev
```

### Daily Development

```bash
# Option 1: Watch mode (recommended)
npm run watch
# Leave this running in a terminal
# Files auto-rebuild when you save

# Option 2: Manual rebuild
# Edit files in assets/src/js/
npm run build:dev
# Refresh browser to see changes
```

### Before Committing

```bash
# Build production bundle
npm run build:prod

# Verify both bundles exist
ls -lh out/js/stripe.*.js

# Test in production mode
# Set STRIPE_DEV_MODE=0 in .env
```

### Before Deployment

```bash
# Clean install
rm -rf node_modules package-lock.json
npm install

# Production build
npm run build:prod

# Verify output
ls -lh out/js/
```

---

## Adding New Controllers

### Step 1: Create Controller File

```bash
# Create new controller
touch assets/src/js/controllers/my_feature_controller.js
```

```javascript
// assets/src/js/controllers/my_feature_controller.js
import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
  static values = {
    apiUrl: String,
    userId: String
  }

  static targets = ["output"]

  connect() {
    console.log('My Feature controller connected')
    this.loadData()
  }

  async loadData() {
    // Your implementation
  }
}
```

### Step 2: Register in App Entry Point

```javascript
// assets/src/js/app.js
import { Application } from "@hotwired/stimulus"

import BuyNowController from "./controllers/buy_now_controller"
import StripeOrderController from "./controllers/stripe_order_controller"
import MyFeatureController from "./controllers/my_feature_controller" // Add this

window.Stimulus = Application.start()

Stimulus.register("buy-now", BuyNowController)
Stimulus.register("stripe-order", StripeOrderController)
Stimulus.register("my-feature", MyFeatureController) // Add this
```

### Step 3: Update Development Entry Point

```javascript
// assets/src/js/app.dev.js
async function loadControllers() {
  const controllers = [
    { name: 'buy-now', path: './controllers/buy_now_controller.js' },
    { name: 'stripe-order', path: './controllers/stripe_order_controller.js' },
    { name: 'my-feature', path: './controllers/my_feature_controller.js' } // Add this
  ]
  // ... rest of code
}
```

### Step 4: Build

```bash
npm run build:dev
```

### Step 5: Use in Template

```twig
<div data-controller="my-feature"
     data-my-feature-api-url-value="{{ apiUrl }}"
     data-my-feature-user-id-value="{{ userId }}">

    <div data-my-feature-target="output"></div>
</div>
```

---

## Troubleshooting

### Bundle Files Not Found

**Error:** `stripe.min.js` or `stripe.dev.js` not found

**Solution:**
```bash
# Rebuild bundles
npm run build:prod
npm run build:dev

# Verify files exist
ls -lh out/js/stripe.*.js
```

---

### Controller Not Connecting

**Error:** Controller doesn't initialize, no console logs

**Checklist:**
1. ✅ Is the bundle loaded in HTML?
   ```twig
   <script src="{{ oViewConf.getModuleUrl('oe_payments_stripe_wallet', 'out/js/stripe.min.js') }}"></script>
   ```

2. ✅ Is `data-controller` attribute correct?
   ```html
   <div data-controller="stripe-order">  <!-- kebab-case -->
   ```

3. ✅ Is controller registered in `app.js`?
   ```javascript
   Stimulus.register("stripe-order", StripeOrderController)
   ```

4. ✅ Did you rebuild after changes?
   ```bash
   npm run build:dev
   ```

5. ✅ Check browser console for errors
   - Open DevTools (F12)
   - Check Console tab
   - Look for Stimulus errors

---

### Values Not Passed to Controller

**Error:** `this.publishableKeyValue` is empty or undefined

**Checklist:**
1. ✅ Value declared in controller?
   ```javascript
   static values = {
     publishableKey: String  // camelCase
   }
   ```

2. ✅ HTML attribute uses correct naming?
   ```html
   data-stripe-order-publishable-key-value="pk_test_..."  <!-- kebab-case -->
   ```

3. ✅ Twig variable is not empty?
   ```twig
   {{ dump(oViewConf.getStripeWalletConfig().getPublishableKey()) }}
   ```

4. ✅ Check in browser DevTools:
   - Inspect element
   - Check `data-` attributes are present
   - Values should be visible in HTML

---

### Changes Not Reflected

**Problem:** You edit code but see no changes in browser

**Solutions:**

1. **Rebuild bundles:**
   ```bash
   npm run build:dev
   ```

2. **Clear browser cache:**
   - Hard refresh: `Ctrl+F5` (Windows/Linux) or `Cmd+Shift+R` (Mac)
   - Or open DevTools → Network tab → Disable cache

3. **Use watch mode:**
   ```bash
   npm run watch
   # Keeps running, auto-rebuilds on save
   ```

4. **Check file timestamps:**
   ```bash
   ls -lh out/js/stripe.dev.js
   # Should show recent time
   ```

---

### Old Bundle Being Loaded

**Problem:** Browser loads old version of `stripe.min.js`

**Solutions:**

1. **Check cache busting:**
   ```twig
   {# Version query parameter forces reload #}
   <script src=".../stripe.min.js?v={{ oViewConf.getStripeModuleVersion() }}"></script>
   ```

2. **Clear OXID cache:**
   ```bash
   cd /path/to/oxid
   ./vendor/bin/oe-console oe:cache:clear
   ```

3. **Clear browser cache completely:**
   - Chrome: Settings → Privacy → Clear browsing data
   - Or use Incognito mode for testing

---

### Build Fails

**Error:** `npm run build` fails with errors

**Solutions:**

1. **Check Node.js version:**
   ```bash
   node --version  # Should be v18+ or v20+
   ```

2. **Reinstall dependencies:**
   ```bash
   rm -rf node_modules package-lock.json
   npm install
   ```

3. **Check for syntax errors:**
   ```bash
   # Lint your JavaScript
   npm run build:dev
   # Check error messages
   ```

4. **Verify package.json:**
   - Check scripts section
   - Ensure paths are correct (`out/js/` not `assets/js/`)

---

### Stimulus Not Defined

**Error:** `Uncaught ReferenceError: Stimulus is not defined`

**Solution:**

Bundle includes Stimulus, but might not be loading:

```bash
# Check bundle size (should be ~48KB for prod)
ls -lh out/js/stripe.min.js

# Rebuild if size is wrong
npm run build:prod

# Verify bundle contains Stimulus
grep -q "Stimulus" out/js/stripe.min.js && echo "✓ Stimulus found" || echo "✗ Stimulus missing"
```

---

## Performance Tips

### Production Bundle Optimization

The production bundle (`stripe.min.js`) is optimized for:
- **Small size:** 48KB minified
- **Fast loading:** Single HTTP request
- **Fast parsing:** Minified code

### Development Bundle Features

The development bundle (`stripe.dev.js`) provides:
- **Readable code:** For debugging
- **Source maps:** Links back to original files
- **Debug logging:** Extra console output
- **Individual controllers:** In `out/js/controllers/` for debugging

### When to Use Each

| Environment | Bundle | Size | Debug | Source Maps |
|-------------|--------|------|-------|-------------|
| Production | `stripe.min.js` | 48KB | ❌ | ✅ |
| Development | `stripe.dev.js` | 90KB | ✅ | ✅ |
| Watch Mode | Auto-rebuild | - | ✅ | ✅ |

---

## References

- [Stimulus.js Documentation](https://stimulus.hotwired.dev/)
- [esbuild Documentation](https://esbuild.github.io/)
- [OXID eShop Module Development](https://docs.oxid-esales.com/)

---

## Support

For issues related to:
- **Frontend build process:** Check this document
- **Stimulus controllers:** See [Stimulus Documentation](https://stimulus.hotwired.dev/)
- **Stripe integration:** See `STRIPE_PAYMENT_FORM_OPTIONS.md`
- **Module installation:** See `QUICK_START.md`

---

**Last Updated:** 2025-11-18
**Version:** 1.0.0
