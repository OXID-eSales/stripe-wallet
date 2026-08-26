/**
 * One Stripe Embedded Checkout per page, whoever asks.
 *
 * Stripe allows a single Embedded Checkout object per document, and two hosts can
 * sit on the same checkout page: the classic order page's order-submit
 * controller and the OPC footer widget. Each used to call
 * initEmbeddedCheckout() on its own, so the page ended up with two live sheets —
 * or, when the second attempt lost the race, with
 * `IntegrationError: You cannot have multiple Embedded Checkout objects`
 * rendered where the shopper reads error messages.
 *
 * This is the OPC-132 registry the footer widget already used to serialise its
 * own re-mounts, lifted out so both hosts share it. Every mount (a) waits for any
 * in-flight init, (b) destroys whatever instance is alive — no matter which host
 * created it — and only then (c) inits its own.
 *
 * The registry lives on `window` because the two hosts are built separately: this
 * module is bundled, the footer widget's copy is inline in
 * views/twig/widget/checkout/stripe-footer.html.twig. Both must use this key.
 */
const REGISTRY_KEY = '__oeStripeEmbeddedRegistry'

function registry() {
  window[REGISTRY_KEY] = window[REGISTRY_KEY] || { chain: null, instance: null }
  return window[REGISTRY_KEY]
}

/**
 * Create the page's Embedded Checkout, replacing any instance already there.
 *
 * @param {Stripe} stripe
 * @param {string} clientSecret
 * @returns {Promise<object>} the embedded checkout instance
 */
export function initEmbeddedCheckoutOnce(stripe, clientSecret) {
  const g = registry()

  g.chain = (g.chain || Promise.resolve())
    .then(() => {
      if (g.instance && typeof g.instance.destroy === 'function') {
        try {
          g.instance.destroy()
        } catch (e) {
          /* already torn down */
        }
      }
      g.instance = null
      return stripe.initEmbeddedCheckout({ clientSecret })
    })
    .then((embedded) => {
      g.instance = embedded
      return embedded
    })

  return g.chain
}

/**
 * Give up an instance this host created, so a later mount does not destroy a
 * handle that is already dead.
 *
 * @param {object|null} instance
 */
export function forgetEmbeddedCheckout(instance) {
  const g = window[REGISTRY_KEY]
  if (g && instance && g.instance === instance) {
    g.instance = null
  }
}
