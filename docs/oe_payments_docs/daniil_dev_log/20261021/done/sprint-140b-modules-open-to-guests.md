# Subsprint 140b — stripe, mollie, paypal: the checkout mutations accept an anonymous token

**Repos:** stripe, mollie-payment, paypal · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md) · **After:** payment-base 1.5.0 pinned

## Finding (report #4)

`StripeCheckout`, `MollieCheckout`, `PayPalCheckout` controllers carry `#[Logged]`, which is false for
anonymous tokens; `#[Right('PAYMENT_CHECKOUT')]` alone is the documented guard.

## Stories (test first, per module — one feature branch each)

| #  | Story                                                                                                                                                                                                                                      |
| -- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| B1 | `testCheckoutStart_AnonymousTokenWithRight_IsAccepted`, `…_AnonymousTokenWithoutRight_IsRefused`: remove `#[Logged]` from Start/Return/Cancel, keep `#[Right]`; the schema test shows the mutations unchanged.                    |
| B2 | `<provider>CheckoutStart` input gains the optional `guest: { email, invoiceAddress, deliveryAddress }` argument (input type defined once in payment-base and reused — DRY), mapped into `HeadlessStartRequest::guestDetails`.           |
| B3 | Return/Cancel for a guest: the contract token (138b hash) is the only credential, as today; test that a different anonymous token with the right contract token succeeds and a wrong token fails.                                       |
| B4 | Integration on the sandbox: guest start → provider page → return → order created with the guest's addresses; proof (order id, admin screenshot) in the report; CHANGELOGs; the module's headless doc gets a guest example.                 |

## Acceptance

Each module's three mutations work with an anonymous token carrying `PAYMENT_CHECKOUT`, and refuse
one without it; logged-in behaviour unchanged (existing tests untouched); CI green per module.

## Out of scope

Express buttons on the product page (PayPal/Apple Pay) — separate sprint.
