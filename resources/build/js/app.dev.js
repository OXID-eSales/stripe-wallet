/**
 * Stripe Module - Development Entry Point
 *
 * Loads controllers individually for easier debugging
 * Source maps point to original files
 */

import { Application } from "@hotwired/stimulus"

// Start Stimulus application
window.Stimulus = Application.start()

// Enable debug mode in development
Stimulus.debug = true

console.log('🔧 Stripe Module: Development mode')
console.log('Stimulus debug enabled')

// Load controllers dynamically in development
// This allows hot reloading and easier debugging
async function loadControllers() {
  const controllers = [
    { name: 'buy-now', path: './controllers/buy_now_controller.js' },
    { name: 'stripe-order', path: './controllers/stripe_order_controller.js' }
    // Add more controllers here as you create them
  ]

  for (const { name, path } of controllers) {
    try {
      const module = await import(path)
      const Controller = module.default
      Stimulus.register(name, Controller)
      console.log(`✅ Registered controller: ${name}`)
    } catch (error) {
      console.error(`❌ Failed to load controller ${name}:`, error)
    }
  }

  console.log('Registered controllers:', Array.from(Stimulus.router.modulesByIdentifier.keys()))
}

// Load controllers
loadControllers().then(() => {
  console.log('🚀 Stripe Module: All controllers loaded and ready')
})

// Expose for debugging
window.StripeDebug = {
  stimulus: Stimulus,
  reloadController: async (name) => {
    console.log(`🔄 Reloading controller: ${name}`)
    // Force reload would go here
  }
}
