/**
 * The page-wide record of who holds Stripe's one Embedded Checkout.
 *
 * Stripe allows a single Embedded Checkout object per document, and two hosts can
 * sit on the same checkout page: the classic order page's order-submit controller
 * and the OPC footer widget. On the order page the order-submit host owns the
 * sheet — its container is the visible one and its Place-Order button is hidden
 * in eager mode — so the footer widget stands down there
 * (see views/twig/widget/checkout/stripe-footer.html.twig).
 *
 * What is shared is only the *record* of the live instance, on the same window key
 * the footer widget's OPC-132 mount serialisation already uses, so that
 * serialisation can retire an instance this host created rather than trip over it.
 *
 * Sharing the creation itself was tried and reverted: one instance handed between
 * hosts gets mounted by whichever calls mount() last, and the order page's visible
 * container was left empty while the sheet sat in the footer's zero-height one.
 */
const REGISTRY_KEY = '__oeStripeEmbeddedRegistry'

/**
 * Record an instance this host created, so the OPC footer widget's mount
 * serialisation can find and retire it instead of tripping over it.
 *
 * @param {object|null} instance
 */
export function registerEmbeddedCheckout(instance) {
  window[REGISTRY_KEY] = window[REGISTRY_KEY] || { chain: null, instance: null }
  window[REGISTRY_KEY].instance = instance
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
