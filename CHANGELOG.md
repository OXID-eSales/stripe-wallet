# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed (VR-16 O6) - webhook endpoint registration is idempotent
- `WebhookEndpointRegistrar::register()` without a stored endpoint id looks for the account's endpoint with the same URL
  and updates it (to `WebhookEventCatalog`) instead of creating a second one. Stripe never re-emits a signing secret on
  update: a reused endpoint whose secret the shop does not hold needs "clear all" + register once.
- `StripeWebhookEndpointApi::listAll()` follows `starting_after` pagination (it stopped at 100 endpoints).

### Changed - adopts payment-base VR wave 4 (H6/DC-PB7/D3/L1)
- **H6**: `StripePaymentOwnershipProvider` (tag `oe.payment.ownership_provider`, owns `oe_payments_stripe_wallet`) and
  `StripeClientConfigSecretPatternProvider` (tag `payment_base.client_config_secret_pattern_provider`: `sk_`, `rk_`,
  `whsec_`) replace the knowledge payment-base's admin API and client-config guard used to hold about Stripe.
- **DC-PB7b**: removed `WebhookDbLogServiceFactory` (+ test) and the two `services.yaml` blocks that built payment-base's
  `WebhookLogService` (nothing injected it). Stripe's webhook DB log is unchanged: it is the claim row written by
  `AbstractWebhookProcessor` / `WebhookLogRepositoryInterface::claimEvent()` / `updateStatus()`; the file log stays on
  `Stripe\Service\WebhookLogService`.
- **D3**: `StripeAcpCheckoutService` takes its four headless collaborators as required arguments; the module's
  `EventBroker` / `EventBrokerInterface` and `CheckoutReturnResponder` definitions are gone (payment-base defines them).
- Tests: DC-PB7c (local `AbstractProviderRequestEvent` subclass for the unmapped event), DC-PB7d (own counting rate-limit
  store), D3 constructor arguments.

### Changed (VR-20 R5) - the return leg runs on payment-base's guard and shared return token
- **Return URLs carry payment-base's `ContractReturnTokenService` token** (shopper-bound, 24 h by default, secret from
  `oe_payments_secrets`), issued by `StripeCheckoutSessionHandler` (Twig) and `StripePaymentHandler` (OPC); both take
  `ContractReturnTokenServiceInterface` instead of `TokenServiceInterface`. Before, the token had no expiry, no shopper
  and a secret derived from the API key (the webhook secret when none was set).
- **`checkoutSuccess` asks `ReturnAccessGuardInterface`** (`ControllerRequestHelper::checkReturnAccess()`): the contract
  exists, is Stripe's, and the caller holds a valid return token **or is the contract's shopper in the session that
  started the payment**. A return without a token is no longer refused for that alone: the shopper's own session opens
  it. Unknown ownership (before: the check was skipped for a guest or an unknown user, so anyone with a valid token
  passed) is now a refusal; the payment is then left to Stripe's webhook, which commits without a browser.
  `CheckoutReturnInputsResolver::checkOwnership()` is gone; `CheckoutReturnRejection` has `AccessDenied`, `TokenExpired`,
  `ProviderMismatch` (via `fromGuard()`) in place of `InvalidContractToken` and `ContractMismatch`.
- **Contracts record `initiating_session_id`** at creation (`StripeContractCreationHandler` from the controller's
  session id, `StripePaymentHandler` for the OPC path). A headless contract records none (no shop session): its return is
  payment-base's own token check.
- `CheckoutReturnService::validateReturn()` and `StripeReturnResolver` no longer validate a token (it was checked twice,
  and in the headless flow Stripe generated the token it then validated itself); `stripeCheckoutReturn` passes the Stripe
  session id only. `GraphQL\Controller\StripeCheckout` loses its token service argument.
- **Legacy window.** `ContractTokenService` is gone; its validation half is `Service\LegacyReturnTokenValidator`
  (`generateToken()` throws), passed to the guard as `ReturnAccessRequest::$legacyTokens`: URLs of checkouts started
  before the update still open while payment-base's setting `blPaymentBaseAcceptLegacyReturnTokens` is on (one release).
  Delete the class with that setting. The `TokenServiceInterface` alias in services.yaml (it resolved to another
  provider's service when that module was active) is removed.

### Changed (VR-23 T1/T3/T5) - late money, Connect origin check, sweep as cron
- **Provider payment gateway (T1).** New `Service\StripeProviderPaymentGateway` (tag
  `oe.payment.provider_payment_gateway`): `statusOf()` (`succeeded` = paid, `requires_capture` = authorized, anything
  else holds no money), `refund()` (the whole charge, idempotency key of the caller), `release()` (cancels the
  PaymentIntent). payment-base's stale-order cleanup asks it before it cancels anything: an order Stripe holds money
  for is committed instead of cancelled, and without the gateway the cleanup would throw per such order.
- **Late money (T5).** `StripeLatePaymentRefunder` is removed. `checkout.session.completed`, `payment_intent.succeeded`
  and `payment_intent.amount_capturable_updated` hand a payment on a contract that can no longer take it to
  payment-base's `LateMoneyResolverInterface::resolve()`: an attempt the customer replaced (as before) **and now a
  contract that is cancelled, expired or failed** (the stale-order cleanup, an admin). The money is refunded or the
  authorization released once and Stripe is answered 200 (before, the paid event of a cancelled contract met a contract
  that could not take it). A refusal raises the at-risk flag `late_money_not_returned` inside the resolver. Nothing
  else raises a flag for Stripe (disputes are deleted on another branch, no dispute flag).
- **Cancel/fail by metadata (T3).** `payment_intent.canceled` and `payment_intent.payment_failed` find their contract by
  the PaymentIntent id and, while the contract still carries the Checkout Session id (`cs_...`), by the metadata
  `contract_id` of the current attempt (`AbstractStripeWebhookHandler::applyToContractOfPaymentIntent()`); an event of
  a replaced attempt does not touch the contract. The two PaymentIntent handlers share
  `contractOfPaymentIntent()` (was a private copy each).
- **Connect account and livemode (T3, S-2).** The platform's Connect endpoint receives the events of every connected
  account, live and test. `StripeWebhookProcessor` now asks `WebhookOriginPolicyInterface`
  (`ShopWebhookOriginPolicy`) before any handler: an event of the other mode (`ignored_livemode_mismatch`) or of another
  account (`ignored_foreign_account`) is answered 200 with that action and touches nothing. The shop's account
  (`acct_...`) is kept per mode in the oxconfig variables `sStripeShopAccountIdTest` / `...Live`: stored when the
  webhook is created in the module settings, and for a shop registered before, asked of Stripe once with the shop's
  key (`ShopStripeAccount`). Events without an `account` (a direct key) are judged by mode only.
- **Sweep as cron (T3).** The stale NOT_FINISHED sweep no longer runs inline after every webhook (it was two Stripe round
  trips per contract on the request Stripe waited for). `WebhookController` answers and ends; the sweep is the console
  command `oe:payments:stripe:sweep [--batch=50]` (`StaleCheckoutSweepCommand`, stale horizon from the stale-checkout
  setting): **schedule it every few minutes** (cron), or abandoned checkouts are only released by
  `oe:payments:not_finished:cleanup`.
- `StripeWebhookProcessor` takes the origin policy as its fifth constructor argument (wired in services.yaml).

### Changed (VR-22 A5) - Stripe's admin capture, refund and void run on payment-base's MoneyOperationRunner
- New `Admin\StripeMoneyGateway` (tag `oe.payment.gateway`, one class for `CaptureGatewayInterface`,
  `RefundGatewayInterface`, `CancelGatewayInterface`). payment-base's `MoneyOperationRunner` now owns the admin API's
  and the classic admin's capture, refund and void: rights, idempotency, per-order lock, amount policy
  (capturable = authorized less captured, refundable = captured less refunded) and the booking. A Stripe refusal reaches
  the admin as `AdminActionRefusedException` carrying the operation and Stripe's error code, never Stripe's text.
- **Capture id = PaymentIntent id.** Stripe captures a PaymentIntent once (a partial capture releases the rest), so the
  capture ledger (`ProviderCaptureLedger`) is keyed by the PaymentIntent id everywhere: the admin capture, the webhook
  (`ContractCaptureRecorder`, also used by the backfill command, now `record($contract, $amount, $paymentIntentId)`
  and constructed with the ledger and a logger) and the broker's capture (`CaptureService`). Whichever reports first
  books it, the others change nothing. A contract that already carries a captured amount is left alone (its
  redelivered webhook cannot add it again). The contract's state after an admin capture follows Stripe's
  `payment_intent.succeeded` webhook, as before for the Twig flow.
- `CaptureService::processDirectCapture()` (capture of a caller-given PaymentIntent without a contract, D-8) is gone;
  `captureAtStripe()` only asks Stripe and books nothing. `StripeCaptureRequestHandler` captures only for a contract
  (`Contract ID is missing` otherwise); a caller-provided PaymentIntent is never captured.
  `CaptureService` takes the capture ledger instead of the transaction repository and the shop adapter.
- Removed: `Controller\Admin\OrderActionDispatcher` (and its caller-provided PaymentIntent branch);
  `StripePaymentPanelProvider::handleAction()` capture/refund/cancel branches (the method throws
  `UnsupportedPaymentActionException`: the panel has no other action), its amount validation and reason checks;
  `Admin\AdminAmountValidator`, `AmountValidationResult`, `AdminRefusalSentence`, `AdminValidationFeedback(Interface)`
  and the panel's `validationErrors` block (refusals are payment-base's classic feedback now). The classic panel's
  reasons and description limits stay (`actionOptions()`); `boundsFor()` stays as a read model.
- **Merchant-visible:** the classic admin needs the payment right for capture and refund, audits them, and where the
  shop requires a second factor (admin API MFA setting) refuses capture and refund and points at the admin API.
  Character validation of the free-text fields is payment-base's. The broker handlers (`Stripe*RequestHandler`) stay for
  opalreturns' refund intents.

### Changed (VR-24 H8) - ACP ownership guard
- `StripeAcpCheckoutService` inherits payment-base's guard: get/update/cancel/complete serve only a contract this agent
  opened over ACP (`channel=acp`, `acp_agent_id`); the unit test fixtures carry both, and a test pins that another
  agent's checkout is never charged and answers "not found". MCP/ACP is kept.

### Changed - Adopts payment-base VR wave 2 (F3/L1/A1)
- **Stock is restored by payment-base, not by Stripe (VR-21 F3).** Removed: `Stripe\Service\OxidStockRestorationService`
  with its services.yaml binding and autowire exclude and `StockRestorationServiceTest`; `RefundService` no longer takes
  a `StockRestorationServiceInterface`, no longer calls `restoreStockForOrder()` and no longer computes whether a refund
  "refunds the rest" (its `refundsTheRest()` and `restoreStock()` are gone). Every refund is booked through
  `ProviderRefundLedgerInterface::book()` with the new `$succeeded` flag (Stripe's refund status is `succeeded`; pending
  is false); payment-base's `RefundSettledPolicy` returns the stock once a succeeded refund leaves nothing refundable.
  `ChargeRefund` carries `succeeded` (from the webhook event's refund list and from `ChargeRefundLister`).
- **Webhook processor (VR-18 L1).** `StripeWebhookProcessor` passes payment-base's `ThrowableSummaryInterface` to
  `AbstractWebhookProcessor` (wired in services.yaml).
- **Money invariants (VR-22 A1).** No Stripe flow books more than authorized/captured: the webhook capture record is
  skipped once a captured amount exists and the admin capture is bounded by Stripe's capturable amount, so nothing needed
  changing. Stripe still books no chargebacks.

### Security
- **The legacy Payment Element path is removed (VR-20 R2, S-1).** `StripeOrderController::executeStripePayment` and
  `::stripeReturn` took the PaymentIntent id from the request (`payment_intent`, `payment_intent_id`) and authorized the
  session's contract with it; nothing tied that PaymentIntent to the contract, so one paid PaymentIntent could mark
  other orders paid. No frontend called these routes any more. Removed with them: `StripePaymentExecuteEvent`,
  `StripePaymentReturnEvent`, `StripePaymentStatusHandler`, `StripePaymentReturnHandler`,
  `ControllerRequestHelper::getPaymentIntentIdFromRequest()`, `::getRedirectStatusFromRequest()`,
  `::getSessionPaymentIntentId()` and `StripeOrderController::processContextResults()`. A Stripe payment commits only
  through the Checkout Session return (`checkoutSuccess`, contract token + owner) and the webhook.
  `LegacyPaymentElementPathIsGoneTest` keeps the entry points from coming back.
### Changed (VR-19 W4) - the webhook runs on payment-base's shared guard chain
- The webhook guards are payment-base's `Webhook\Guard\*` classes, wired as `stripe.webhook.guard_chain` (payload size
  64 KB -> HTTPS -> IP allow-list, empty by default -> rate limit 100/min). The module's copies (`WebhookGuardChain`,
  `WebhookGuardResult`, `WebhookRequestGuardInterface`, `WebhookHttpsGuard`, `WebhookPayloadSizeGuard`,
  `WebhookRateLimitGuard`, `WebhookIpAllowlistGuard`) and the APCu rate limiter service are removed.
- The rate limit now counts in payment-base's shared store (`oe_payments_idempotency`, per provider `stripe`) and keys on
  the caller as payment-base's `TrustedProxyClientIp` resolves it behind the shop's reverse proxies (payment-base
  settings "Webhooks"). Without the chain or the resolver the endpoint answers 503, as it did without the chain.
- The "minimal guard chain" degraded mode is gone: there is no second, weaker chain, the endpoint either has the shared
  chain or refuses. Rejections now answer with the guard's reason code (`tls_required`, `payload_too_large`,
  `rate_limited`, `ip_not_allowed`) instead of a sentence; the HTTP statuses are unchanged. The loopback exemptions of the
  HTTPS and IP allow-list guards are removed (HTTPS is judged by `HTTPS` / `X-Forwarded-Proto` only). Requires
  payment-base `b-7.4.x` from 2026-11-03 (`TrustedProxyClientIp` service). After upgrading clear the container cache.

### Fixed
- **Every Stripe refund is booked once, by Stripe's refund id, for the amount Stripe refunded (VR-21 F5).** The admin
  refund and the `charge.refunded` webhook booked refunds separately and raced each other without a lock; an event with
  no refund list booked "the difference" under an empty refund id, which defeated the dedupe; and the admin path booked
  the contract's authorized amount instead of what Stripe refunded (wrong after a partial capture). Both paths now book
  through payment-base's `ProviderRefundLedgerInterface` (needs payment-base with the ledger, 1.7.0): one booking per
  refund id whichever path reports it first, the refund's transaction row written by the ledger (the module no longer
  writes its own refund row). The webhook books every refund of the charge by its id; an event that carries no
  `refunds.data` (newer API versions) or carries it cut short (`has_more`) is completed from Stripe's refund list of
  the charge, and a failure asking Stripe fails the delivery so Stripe retries. A refund Stripe did not run (failed,
  canceled) is not booked; a refund response without a refund id is a booking error, no longer `unknown`. The refund
  restock rule of VR-21 F2 is unchanged. `StripeRefundRequestHandler` and `WebhookContractFulfillmentHandler` take the
  ledger as a required dependency (no more `new ContractRefundRecorder` fallback). After upgrading clear the container
  cache.
- **A partial or pending refund no longer restocks the order (VR-21 F2).** Every succeeded or pending refund, of any
  amount, returned all order lines to stock and stornoed them. Stock now comes back only when the refund succeeded and
  leaves nothing refundable on the charge (no amount, or captured minus already refunded). A pending refund that later
  succeeds is not restocked by this path.
- **A refund is booked once (CFG-1 S8).** An admin refund and Stripe's `charge.refunded` webhook for the same refund both
  booked it: the contract's refunded amount doubled and a second, reference-less refund row appeared. The webhook now
  books only refunds the module does not know, told apart by Stripe's refund id (the reference of the admin's row); an
  event without a refund list is reconciled against the cumulative `amount_refunded` the contract already carries. This
  also fixes dashboard refunds: several partial refunds added Stripe's cumulative total each time, and a replay of an older
  event added again. Dashboard refunds now keep their `re_...` id as the row's reference. Admin capture was not affected
  (the contract's captured amount guards the webhook). After upgrading clear the container cache.
- **Manual capture and refund from the admin (CFG-1 S8, found by a Stripe test-mode run from the admin app).** An admin
  capture now records the captured amount on the contract (it wrote the transaction row and moved the state, but the
  contract kept 0.00 captured until a webhook that may never come), and an admin refund now writes its row to the
  transaction table with Stripe's refund id as reference (only the `charge.refunded` webhook wrote one, without the id), so
  the admin's Payment tab lists every capture and refund with its provider reference. After upgrading clear the container
  cache.

### Removed
- **Dead code removal (VR-17).** Public classes: `Adapter\StripeWebhookEvent`, `Adapter\OxidShopOrderService` (the
  module no longer ships an order service of its own; payment-base's `OxidShopOrderService` finalizes orders),
  `Webhook\Handler\ChargeDisputeCreatedWebhookHandler` (the event was never subscribed), `Twig\DumpExtension`,
  `Environment\DevelopmentEnvironmentCheckerInterface`, `Environment\ModuleConfigurationDevelopmentChecker`, and
  `Service\StripeRadarFraudCheckService` with the `FraudCheckHandler` wiring and the `payment.fraud_check.enabled`,
  `payment.fraud_check.threshold` and `payment.fraud_check.fail_open_on_error` container parameters (the check read a
  metadata key nothing wrote, so it never screened anything; Stripe contracts carry no `fraud_check` condition).
  Methods: `StripeAdapter::getPaymentIntentRiskScore()` and `PaymentIntentHelper::getRiskScore()` (also on
  `StripePaymentIntentAdapterInterface`), `StripeAdapter::testConnection()` (also on `StripeCustomerAdapterInterface`),
  `StripeClientFactory::isValidSecretKey()`, `StripeDefinitions::supportsCurrency()` / `::supportsCountry()`,
  `ViewConfig::isStripeDebugEnabled()`, `Admin\ModuleConfiguration::stripeIsTestMode()` / `::stripeHasApiKeys()`,
  `StripeCaptureRequestEvent::isFullCapture()` / `::getIdempotencyKey()`, and on `ModuleConfigurationService(Interface)`:
  `is3DSecureEnabled()`, `isTransactionLoggingEnabled()`, `isRemoveByBillingCountry()`, `isRemoveByBasketCurrency()`.
  `StripeAdapter::parseWebhook()` stays (payment-base's adapter interface declares it) but throws `LogicException`:
  webhooks are parsed by `StripeWebhookProcessor` only.
- **Settings:** `blStripeRemoveByBillingCountry` and `blStripeRemoveByBasketCurrency` (no runtime effect, only displayed in
  the admin) and `blStripeLogTransactionInfo` (deprecated; it seeded `sStripeLogLevel` when that was unset: an unset level
  is now `normal`, the legacy toggle is not read any more). Their lang keys are removed too.
- **GraphQL:** the fields `removeByBillingCountry` and `removeByBasketCurrency` of `AdminStripeConfig`. Clients (the
  oxid-fe-admin Stripe plugin) must stop selecting them. After upgrading: `bin/oe-console oe:graphql:cache-clear`.
- **Frontend:** the Payment Element leftovers: the `select_payment` / `change_payment` overrides in
  `page/checkout/payment.html.twig`, `frontend/stripe_card.html.twig`, `frontend/select_payment.html.twig`,
  `frontend/base_stripe_element_config.html.twig`, `frontend/base_stripe_payment_controller_config.html.twig`,
  `blocks/payment_stripe_form.html.twig`, the `stripe-order` Stimulus controller (and the card branch of `order-submit`),
  `stripe_payment_element.css`, the unused `stripe-admin.min.js` bundle and its build target. `assets/js/stripe-frontend*.js`
  are rebuilt.
- **Translations:** 115 frontend and 52 admin lang keys per language that nothing references any more.
- **`ContractRefundRecorder` (VR-21 F5):** payment-base's ledger records refunds on the contract.
- **CFG-1: no GraphQL path changes the module's settings any more** (they change in the classic admin only):
  `adminStripeConfigModify`, `adminStripeWebhookRegister` and `adminStripeWebhookClearAll`, with `AdminStripeConfigInput`,
  `AdminStripeConfigPayload`, `AdminStripeWebhookPayload`, `AdminStripeConfig.secretsEditable`, the modifier, change
  validator, audit and result classes, and the write side of the settings and webhook-state seams. Kept: `adminStripeConfig`,
  `adminStripeWebhookStatus`, `AdminPaymentContract.stripe` and payment-base's capture / refund / void. After upgrading:
  `bin/oe-console oe:graphql:cache-clear`.

### Added
- **Stripe admin API (PAY-3)** for `oxid-admin-graphql` (optional, see `docs/for_developer/06-admin-api.md`):
  `adminStripeConfig`, `adminStripeWebhookStatus` (read-only, see Removed), and `AdminPaymentContract.stripe` (payment intent, charge, status, dashboard link; fails
  closed when Stripe cannot be reached). Rights: `ADMIN_PAYMENT_CONFIG` / `ADMIN_PAYMENT_VIEW`.
- Provider wiring of payment-base's PAY-1 admin payment API: Stripe's column of the contract-state Help, the reasons and
  description the panel accepts, live capture/refund bounds, and readable refusals (`AdminActionRefusedException`) for the
  amount and text gate of the Payment tab; the classic tab behaves as before.
- **PAY-8:** `AdminStripeConfig.classicSettingsUrl` (read-only, no secret): the classic admin's settings page of this module,
  so the new admin can link to the Connect onboarding (`ModuleConfigurationServiceInterface::getClassicSettingsUrl()`).
- `ConfigurationValidator::getKeyPairError()` (`KeyPairValidatorInterface`).

### Requires
- payment-base with the PAY-1 provider API (next release after 1.5.0; until then CI pins payment-base's branch
  `b-7.4.x-admin-payment-plugins`) and, for the admin API itself, payment-base >= 1.5.

### Changed
- **Docs (138h).** The developer guide's security model (§7) states what the headless channel leaves behind and where
  it does not (hashed contract token, log policy, URLs, browser storage, errors by code, secret scanning); the merchant
  guide lists the log policy and the PayPal webhook id next to what stays in the shop.
- **Guest checkout over GraphQL (140b).** `stripeCheckoutStart`, `stripeCheckoutReturn` and `stripeCheckoutCancel` no longer
  carry `#[Logged]`: the right `PAYMENT_CHECKOUT` alone guards them, so an anonymous token with the right is accepted and one
  without it is refused (logged-in behaviour unchanged). `stripeCheckoutStart` gains the optional argument
  `guest: GuestCheckoutInput` (`email`, `invoiceAddress: GuestAddressInput!`, `deliveryAddress: GuestAddressInput`), the
  input type is payment-base's (`GuestCheckoutInputFactory`, defined once for all provider modules); a refusal of the
  details (`guest_details_required`) surfaces as payment-base's `GuestDetailsRefused`, `account_exists` as
  `StripeCheckoutError`, both with `extensions.errorCode`. The payment
  needs no argument: the mutation already names Stripe itself. Return and cancel stay authorised by the contract token
  alone. `bin/graph-ql-cli-test.sh guest` runs the guest start. Known gap (payment-base 140a): the order ships to the
  invoice address, a basket's delivery address is not applied. The input types moved into payment-base (this module's own
  copy, `GuestCheckoutInputFactory`, is removed; `NamespaceMapper` maps no type namespace any more): the same type names
  defined in two active provider modules would clash, so there is exactly one definition.

### Documentation
- `oe:graphql:cache-clear` after activating or updating the module: setup step and troubleshooting row for
  `Cannot query field "…"` in the merchant guide `02-headless-storefronts.md`, §1 and §11 of the headless developer guide,
  `bin/graph-ql-cli-test.md`; `bin/graph-ql-cli-test.sh` clears the schema cache before its first query
  (`CACHE_CLEAR_CMD`, `SKIP_CACHE_CLEAR=1`); the three `stripe-tests-oxid7.*.yml` workflows run it after the module
  activation.

- Developer guide `05-headless-graphql-checkout.md`: §13 "How this relates to the OXID third-party-payments models"
  (standard / express / own workflow table, why `placeOrder` is refused for our baskets, basket removal on contract
  commit instead of `BeforeBasketRemoveOnPlaceOrder`) and §14 "Versions" (graphql-base 12.x / storefront 4.x; what to check
  before 13 / 5), each statement checked against the code with the class named. The merchant README indexes the headless
  and security-header guides; two dead links there are fixed.

### Security
- Provider failures no longer put provider text into the GraphQL error (and so into `oxideshop.log`): the handler
  answers fixed messages (`Failed to create Stripe checkout session`, `Stripe payment processing failed`),
  `StripeCheckoutError` answers "Stripe could not start the checkout" for `provider_failed`; Stripe's own text goes to the
  logger at `debug` with the contract id, the `error` log line carries the exception class only.

### Fixed
- Stripe Checkout Sessions (hosted and embedded) are created in the shop's active language
  (`locale`, via the new `StripeLocale` and `LanguageResolverInterface::getActiveLanguageAbbreviation()`).
  Without a locale Stripe followed the BROWSER's language, so a German storefront showed an English
  visitor an English payment form. Languages Stripe does not offer fall back to `auto`.
- A Stripe payment confirmed by `payment_intent.succeeded` (or `checkout.session.completed`) now records the
  contract's captured amount (`amount_received`, falling back to `amount`) and writes one `capture` audit row.
  Before, nothing on the webhook path set the captured amount, so the audit row was skipped
  (`Capture audit row skipped: contract has no captured amount`) and refund bounds saw nothing captured.
  A manual capture's own `capture` row is never duplicated. `WebhookContractFulfillmentHandlerInterface::handlePaymentSucceeded()`
  gains an optional trailing `?float $amountReceived`.
### Added
- `stripe:backfill-captured-amount [--apply]`: records the captured amount and the capture audit row on contracts that
  were fulfilled before the fix above. Dry run by default; `--apply` reads each PaymentIntent from Stripe and writes.
  Supported by `Service\ContractCaptureRecorder`, `StripePaymentIntentDto::$amountReceived` and
  `Service\CapturedAmountBackfillService`.
- Sealed client config provider (`ClientConfig\StripeClientConfigProvider`, tag `payment_base.client_config_provider`):
  exposes only the publishable key of the current mode (or nothing when unset or not a `pk_` key) to payment-base's
  `paymentClientConfig` query. Requires a payment-base release that ships `ClientConfigProviderInterface`.
  The provider also implements `AllowListedClientConfigProviderInterface` (payment-base 1.4.0+) and declares
  `publishableKey` as its only value name, so the guard refuses any other name whatever its value looks like.
- Headless checkout over GraphQL. See docs for developers
  (`docs/for_developer/05-headless-graphql-checkout.md`): the `stripeCheckoutStart` / `stripeCheckoutReturn` /
  `stripeCheckoutCancel` API with `uiMode` `hosted` | `embedded`, the complete call sequence of a checkout as the
  reference storefront (oxid-fe-user) performs it, the sealed publishable-key exchange (`paymentClientConfig`:
  ECDH P-256 + HKDF-SHA256 + AES-256-GCM), the webhook as the step that finishes the order, rights and contract
  token, the return-origin allow-list (`sPaymentBaseHeadlessReturnOrigins`), the error codes, and how to build
  another frontend or add another provider. For merchants (`docs/for_merchant/02-headless-storefronts.md`): what a
  headless storefront gets, embedded vs hosted presentation, the setup checklist, troubleshooting, limits.
  Corrects two older statements along the way: `cancelUrl` and `checkoutSessionId` are optional (not `String!`),
  and Stripe appends only `session_id` to the return URL.


## [v3.4.1] - 2026-10-09

### Fixed
- PaymentBase dependancy version

## [v3.4.0] - 2026-10-09

### Added
- A "Help" group at the end of the module Settings tab (MOL-10): payment-base's contract-state table
  (OXID Contract Status · Meaning) with Stripe's third column, the PaymentIntent status each state corresponds to
  (`Admin\StripeContractStateHelp`). The admin order Payment tab shows an "OXID Contract Status" row with a "?" that
  opens payment-base's Help layer (description + three-column table). Requires the matching payment-base version.
- Headless functionality

### Fixed
- Iframe mode painted the Place-Order button instead of the embedded Stripe sheet in every fresh
  browser session (no persisted Terms consent yet). The button is now never the shopper's control in
  iframe mode: the sheet mounts on load when consent is not gated, and the moment the Terms checkbox
  is ticked otherwise (Sprint 137, IFRAME-05).
- With the payment-base "skip" flags on and exactly one payment method and one delivery set, the
  Stripe order page dropped the shipping card entirely and so never named the carrier. It now shows
  the shipping heading and carrier name without the edit pencil, and leaves the payment card out,
  exactly as payment-base's own order template does for non-Stripe orders.
- A Content-Security-Policy `<meta>` rendered inside `<body>` was ignored by browsers and logged an
  error on every Stripe-active page. Removed; CSP belongs in the shop's HTTP headers.

## [v3.3.0] - 2026-09-15

Note: the `v3.2.0` tag sits on a commit that is not part of any branch. Its content is in
`b-7.4.x` through an equivalent commit, so this release continues from there.

### Added
- Stripe Embedded Checkout: the footer widget and the standard order page mount the payment sheet
  inline when payment-base runs in iframe mode, with a page-global singleton and serialised
  mounting so a page can only ever hold one sheet.
- The module declares its iframe UI topology to the One-Page Checkout instead of carrying the
  `sPaymentHandlerUiTopology` setting.
- The single-payment and single-shipping decisions from payment-base 1.2 are honoured in this
  module's own order block and order template.

### Changed
- Idempotency is keyed by request and uses Stripe's native keys, with a required repository and a
  lock reaper; refund idempotency no longer keys by payment.
- Webhook guards fail closed instead of warning and continuing, the webhook secret is scoped per
  mode and the shop id is required.
- One currency resolver replaces the hardcoded `EUR` assumptions, and an unknown amount is
  reported as unknown instead of as zero.
- Radar reports the score it actually has instead of a forged clean one, and `confirmPayment`
  asks Stripe instead of always answering yes.
- Silent handler exits are logged.

### Fixed
- One checkout creates one Stripe session; a retried attempt retires the previous one.
- The checkout return no longer refuses a paid return and explains itself when it does refuse; the
  ownership check survives an absent user.
- `ShopOrderServiceInterface` is no longer bound to this module's adapter.
- The checkout footer shows a translated label and reads the failure key the OPC actually sends.

### Changed (branding)
- The admin module logo is the shared OXID module logo (`assets/img/logo.png`, the same file
  payment-base and the One-Page Checkout carry) instead of the Stripe wordmark. `stripe_logo.png`
  was removed — it was referenced nowhere but in the `thumbnail` entry, so no frontend output
  changes.

### Fixed (found by the standalone unit suite)
- `ShopCurrency::nameOrEmpty()` accepted `?object` while
  `Config::getActShopCurrencyObject()` ends in `reset($currencies)` — which returns `false` on a
  shop with no currency row. That path is display-only and explicitly must not break the page, so
  it now takes `object|false|null`.

### Changed (tests)
- The unit suite runs standalone: `tests/phpunit-unit.xml` with `tests/bootstrap-unit.php`, which
  boots composer's autoloader plus the few things only an activated module would otherwise
  provide (the `*_parent` classes, the shop's global functions, an in-memory `Config`). No shop,
  no database, 1578 tests. The CI job now uses the module's own PHPUnit instead of borrowing the
  shop's binary, and the test namespace moved from `autoload` to `autoload-dev`.
- `testGetCheckoutDataDefaultsCurrencyToEurWhenMissing` promised a default of `EUR` that
  `resolveCurrency()` deliberately does not give ("Never a hardcoded 'EUR', which mislabels the
  checkout footer on any non-EUR shop"). It only passed because the suite ran against a shop whose
  currency happened to be EUR; it now asserts the documented behaviour and is named accordingly.
- The workflows no longer pin payment-base with an explicit `as <version>` alias. payment-base
  declares `extra.branch-alias`, so the branch under test satisfies `>=v1.2` by itself and the
  workflows stop going stale on every payment-base release.

### Packaging
- `composer.json` prepared for publication: `oxid-esales/payment-base` raised to `>=v1.2` — the
  module uses `VoucherReleaseInterface`, which 1.1 does not have; the bound stays open upwards so
  a later release can raise the floor without a constraint rewrite — `doctrine/dbal` widened to
  `^2.13 || ^3.0`, and the undeclared runtime dependencies `ext-json`, `ext-mbstring`, `psr/log`
  and `symfony/console` added. `authors` and `support` added, and the unused `symfony/filesystem` and
  `mikey179/vfsstream` dropped from require-dev. The test namespace deliberately stays in the
  production `autoload` section: CI runs the unit suite through the shop's autoloader, which does
  not read a dependency's `autoload-dev`. `tests/` is kept out of the package by `.gitattributes`
  instead.
- `package.json` is marked `private`, names OXID eSales AG as author and carries
  `SEE LICENSE IN LICENSE` instead of the incorrect `MIT`.
- `composer.lock` and the generated `var/` shop configuration are no longer tracked — a module is
  a library, and shop state is not module source.
- `.gitattributes`: tests (including the 62 MB load-test suite), the generated HTML documentation
  browser, the frontend build chain, CI workflows, the development recipe and developer scripts
  are no longer part of the composer package.

## [v3.2.0] - 2026-08-11

Added Payment Base iframe feature support 

## [v3.1-rc.1] - 2026-07-02

Initial release of Stripe Payment Gateway for OXID eShop 7.4. Based on PaymentBase extension, providing seamless integration with Stripe's API for processing payments, refunds, and handling webhooks. This release includes support for one-time payments, subscriptions, and basic error handling. Future updates will focus on expanding features and improving performance.
