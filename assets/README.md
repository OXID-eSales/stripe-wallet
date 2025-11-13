# Stripe Module - Frontend Assets

This directory contains the frontend JavaScript and CSS source files for the Stripe payment module.

## Structure

```
assets/
├── src/
│   └── js/
│       ├── app.js                          # Main entry point
│       └── controllers/
│           └── buy_now_controller.js       # Stimulus controller for Buy Now button
```

## Technology Stack

- **[Stimulus.js](https://stimulus.hotwired.dev/)** - Modest JavaScript framework
- **[esbuild](https://esbuild.github.io/)** - Fast JavaScript bundler
- **ES6 Modules** - Modern JavaScript module system

## Getting Started

### 1. Install Dependencies

```bash
cd /home/gaad/PhpStormProjects/OXID/Stripe/stripe-wallet/source/extensions/stripe
npm install
```

This will install:
- `@hotwired/stimulus` - Stimulus.js framework
- `esbuild` - JavaScript bundler

### 2. Build JavaScript

```bash
# Production build (minified)
npm run build

# Development build (with source maps, no minification)
npm run dev

# Watch mode (rebuilds on file changes)
npm run watch
```

### 3. Output

Built files are placed in:
```
out/js/
└── stripe.min.js       # Bundled and minified JavaScript
└── stripe.min.js.map   # Source map for debugging
```

## Stimulus Controllers

### Buy Now Controller

**Location:** `assets/src/js/controllers/buy_now_controller.js`

**Purpose:** Handles "Buy Now" button clicks and form submission

**Usage in Twig:**

```twig
<div data-controller="buy-now"
     data-buy-now-product-id-value="{{ productId }}"
     data-buy-now-product-nid-value="{{ productNid }}"
     data-buy-now-parent-id-value="{{ parentId }}"
     data-buy-now-action-url-value="{{ actionUrl }}"
     data-buy-now-csrf-token-value="{{ csrfToken }}">

  <button data-action="buy-now#submit">
    Buy Now
  </button>
</div>
```

**Data Attributes:**

| Attribute | Type | Description |
|-----------|------|-------------|
| `data-controller` | String | Identifies Stimulus controller |
| `data-buy-now-product-id-value` | String | OXID product ID |
| `data-buy-now-product-nid-value` | String | OXID product node ID |
| `data-buy-now-parent-id-value` | String | Parent product ID (for variants) |
| `data-buy-now-action-url-value` | String | Form submission URL |
| `data-buy-now-csrf-token-value` | String | CSRF protection token |
| `data-action` | String | Stimulus action (button click) |
| `data-buy-now-target` | String | Stimulus target (optional) |

**Methods:**

- `connect()` - Called when controller connects to DOM
- `submit(event)` - Handles button click, creates and submits form
- `submitForm(fields)` - Creates hidden form with fields and submits
- `setLoadingState(button, isLoading)` - Shows/hides loading spinner
- `handleError(error)` - Handles errors

## Adding New Controllers

### 1. Create Controller File

```bash
touch assets/src/js/controllers/my_new_controller.js
```

### 2. Write Controller

```javascript
import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
  static values = {
    // Define values here
  }

  connect() {
    console.log("My controller connected")
  }

  // Add your methods
}
```

### 3. Register in app.js

```javascript
import MyNewController from "./controllers/my_new_controller"

Stimulus.register("my-new", MyNewController)
```

### 4. Rebuild

```bash
npm run build
```

### 5. Use in Twig

```twig
<div data-controller="my-new">
  <!-- Your HTML -->
</div>
```

## Stimulus Naming Conventions

### Controller Names
- File: `my_controller.js` (snake_case)
- Register as: `"my"` (kebab-case)
- Use in HTML: `data-controller="my"`

### Values
- Define: `static values = { userName: String }`
- Set in HTML: `data-my-user-name-value="John"`
- Access in JS: `this.userNameValue`

### Actions
- Define method: `submit(event) { }`
- Set in HTML: `data-action="my#submit"`
- With event: `data-action="click->my#submit"`

### Targets
- Define: `static targets = ["button"]`
- Set in HTML: `data-my-target="button"`
- Access in JS: `this.buttonTarget` (single) or `this.buttonTargets` (multiple)

## Debugging

### Enable Stimulus Debug Mode

Edit `assets/src/js/app.js`:

```javascript
// Always enable debug in development
Stimulus.debug = true
```

Then rebuild:
```bash
npm run build
```

### Browser Console

Stimulus logs all controller connections:
```
Stripe Buy Now controller connected {productId: "...", productNid: "..."}
```

### Check Loaded Controllers

In browser console:
```javascript
Stimulus.router.modulesByIdentifier
```

## File Watching for Development

Use watch mode during development:

```bash
npm run watch
```

This will automatically rebuild when you save changes to any `.js` file.

## Production Deployment

### 1. Build for Production

```bash
npm run build
```

### 2. Verify Output

```bash
ls -lh out/js/
# Should show:
# stripe.min.js      - Minified bundle
# stripe.min.js.map  - Source map
```

### 3. Commit Built Files

```bash
git add out/js/stripe.min.js
git add out/js/stripe.min.js.map
git commit -m "Build Stripe module JavaScript"
```

## Troubleshooting

### Controller Not Connecting

**Problem:** No console log "controller connected"

**Solutions:**
1. Check JavaScript is loaded: View Page Source → Look for `stripe.min.js`
2. Check data-controller attribute: `data-controller="buy-now"` (must match registered name)
3. Rebuild JavaScript: `npm run build`
4. Clear browser cache: Ctrl+Shift+R

### Actions Not Firing

**Problem:** Button click does nothing

**Solutions:**
1. Check data-action syntax: `data-action="buy-now#submit"`
2. Check method exists in controller: `submit(event) { }`
3. Check console for errors: F12 → Console tab
4. Enable Stimulus debug: `Stimulus.debug = true`

### Values Not Available

**Problem:** `this.productIdValue` is undefined

**Solutions:**
1. Check static values defined: `static values = { productId: String }`
2. Check HTML attribute: `data-buy-now-product-id-value="..."`
3. Check naming: `productId` → `data-buy-now-product-id-value`

## Resources

- [Stimulus.js Handbook](https://stimulus.hotwired.dev/handbook/introduction)
- [Stimulus Reference](https://stimulus.hotwired.dev/reference/controllers)
- [esbuild Documentation](https://esbuild.github.io/)

## Support

For issues or questions:
- Check console for errors
- Enable Stimulus debug mode
- Check this README
- Review Stimulus documentation
