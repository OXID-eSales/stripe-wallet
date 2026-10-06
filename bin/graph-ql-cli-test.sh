#!/usr/bin/env bash
#
# graph-ql-cli-test.sh — drive the headless Stripe checkout through the
# GraphQL Storefront from the command line (GRAPH-QL / P-Stripe).
#
# Shows what the Stripe module can do without a Twig page: open a contract
# with stripeCheckoutStart (hosted redirect or embedded client secret),
# finish it with stripeCheckoutReturn after the shopper paid on Stripe,
# retire it with stripeCheckoutCancel, see a wrong token refused, and see
# the core placeOrder refused for a basket that pays with Stripe.
#
# Docs and examples: bin/graph-ql-cli-test.md
# Needs: curl, jq. A shop with oe_graphql_base + oe_graphql_storefront,
# payment-base and this module active, Stripe test keys configured.
#
set -euo pipefail

GRAPHQL_URL="${GRAPHQL_URL:-http://localhost.local/graphql/}"
SHOP_URL="${SHOP_URL:-$(printf '%s' "$GRAPHQL_URL" | sed -E 's#(https?://[^/]+).*#\1/#')}"
USER_EMAIL="${USER_EMAIL:-headless.user@oxid-esales.dev}"
USER_PASSWORD="${USER_PASSWORD:-useruser}"
PRODUCT_ID="${PRODUCT_ID:-5e6a374e212258abbfd76b6adf911772}"   # "Panorama", 20.90 EUR in the demo data
DELIVERY_METHOD_ID="${DELIVERY_METHOD_ID:-oxidstandard}"
STATE_FILE="${STATE_FILE:-${TMPDIR:-/tmp}/graph-ql-cli-test.state}"
# The return / cancel URLs belong to the headless client; the shop does not
# serve them. For a test by hand the shop's start page is a friendlier landing
# than a 404: Stripe appends &session_id=... to it.
RETURN_URL="${RETURN_URL:-${SHOP_URL}index.php?cl=start&headless=return}"
CANCEL_URL="${CANCEL_URL:-${SHOP_URL}index.php?cl=start&headless=cancel}"
# OXID empties the basket for user agents it takes for search engines (curl's
# default is one). A real headless client is a browser or an app; look like one.
USER_AGENT="${USER_AGENT:-Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36 graph-ql-cli-test}"
VERBOSE="${VERBOSE:-0}"

TOKEN=""

usage() {
    cat <<USAGE
usage: $(basename "$0") <command> [args]

  schema                  the Stripe mutations and their result types, as the schema exposes them
  start [hosted|embedded] login, create a basket with one product, stripeCheckoutStart (default hosted)
  pay                     = start hosted, then tells you how to pay and how to finish with 'return'
  return <checkoutSessionId> [contractId contractToken]
                          stripeCheckoutReturn — commits the paid contract (ids default to the last start)
  cancel [contractId contractToken]
                          stripeCheckoutCancel — retires the unpaid contract (ids default to the last start)
  wrong-token             stripeCheckoutCancel with a bogus token: refused, nothing changes
  guard                   core placeOrder on a basket paying with Stripe: refused, names stripeCheckoutStart
  demo                    everything that needs no browser: schema, start hosted + embedded, cancel, wrong-token, guard
  raw '<query>'           any GraphQL document, logged in (for your own experiments)

environment (defaults in brackets):
  GRAPHQL_URL [$GRAPHQL_URL]   SHOP_URL [$SHOP_URL]
  USER_EMAIL [$USER_EMAIL]   USER_PASSWORD [***]
  PRODUCT_ID [$PRODUCT_ID]   DELIVERY_METHOD_ID [$DELIVERY_METHOD_ID]
  RETURN_URL / CANCEL_URL (must be under an allowed origin; the shop's own origin always is)
  STATE_FILE [$STATE_FILE]   VERBOSE=1 prints every raw response
USAGE
}

need() { command -v "$1" >/dev/null 2>&1 || { echo "missing: $1" >&2; exit 2; }; }
# Headings and notes go to stderr so that functions returning a value via
# stdout (basket_with_one_product) can still talk.
say() { printf '\n\033[1m%s\033[0m\n' "$*" >&2; }
note() { printf '  %s\n' "$*" >&2; }

# gql '<document>' — POST to the endpoint, Bearer token when logged in. Prints the JSON body.
gql() {
    local body
    body=$(jq -n --arg q "$1" '{query: $q}')
    local -a auth=()
    [ -n "$TOKEN" ] && auth=(-H "Authorization: Bearer $TOKEN")
    local response
    response=$(curl -sS "$GRAPHQL_URL" -A "$USER_AGENT" -H 'Content-Type: application/json' "${auth[@]}" --data-binary "$body")
    [ "$VERBOSE" = "1" ] && printf '%s\n' "$response" | jq . >&2
    printf '%s' "$response"
}

# data '<json>' '<field>' — the field's data, or exit with the GraphQL error.
data() {
    local errors
    errors=$(printf '%s' "$1" | jq -c '.errors // empty')
    if [ -n "$errors" ]; then
        echo "GraphQL error: $(error_of "$1")" >&2
        case "$(printf '%s' "$1" | jq -r '.errors[0].extensions.errorCode // ""')" in
            return_url_rejected) echo "hint: RETURN_URL / CANCEL_URL must be under the shop's own URL or an origin in the setting sPaymentBaseHeadlessReturnOrigins — set SHOP_URL=https://<your shop>/" >&2 ;;
        esac
        exit 1
    fi
    printf '%s' "$1" | jq -c ".data.$2"
}

# error_of '<json>' — "message [errorCode/providerCode]" of a failed response.
# Headless refusals carry a stable extensions.errorCode (payment-base
# HeadlessCheckoutException) and, when the provider refused, a providerCode.
error_of() {
    printf '%s' "$1" | jq -r 'if .errors then "\(.errors[0].message) [\(.errors[0].extensions.errorCode // "-")\(if .errors[0].extensions.providerCode then "/" + .errors[0].extensions.providerCode else "" end)]" else "NO ERROR — unexpected: \(.data|tojson)" end'
}

login() {
    [ -n "$TOKEN" ] && return
    TOKEN=$(data "$(gql "{ token(username: \"$USER_EMAIL\", password: \"$USER_PASSWORD\") }")" token | jq -r .)
    note "logged in as $USER_EMAIL"
}

basket_with_one_product() {
    local basket_id total
    basket_id=$(data "$(gql "mutation { basketCreate(basket: {title: \"graph-ql-cli-$(date +%s%N)\", public: false}) { id } }")" basketCreate.id | jq -r .)
    total=$(data "$(gql "mutation { basketAddItem(basketId: \"$basket_id\", productId: \"$PRODUCT_ID\", amount: 1) { cost { total } } }")" basketAddItem.cost.total)
    if [ "$total" = "0" ] || [ "$total" = "null" ]; then
        echo "basket total is $total — the shop took this client for a bot (user agent) or the product is not orderable" >&2
        exit 1
    fi
    note "basket $basket_id, total $total"
    printf '%s' "$basket_id"
}

save_state() { printf 'CONTRACT_ID=%s\nCONTRACT_TOKEN=%s\nBASKET_ID=%s\n' "$1" "$2" "$3" > "$STATE_FILE"; }
load_state() {
    CONTRACT_ID="${1:-}"; CONTRACT_TOKEN="${2:-}"
    if [ -z "$CONTRACT_ID" ] && [ -f "$STATE_FILE" ]; then
        # shellcheck disable=SC1090
        . "$STATE_FILE"
        note "using the last start: contract $CONTRACT_ID"
    fi
    [ -n "${CONTRACT_ID:-}" ] && [ -n "${CONTRACT_TOKEN:-}" ] || { echo "no contract: run 'start' first or pass <contractId> <contractToken>" >&2; exit 1; }
}

cmd_schema() {
    say "Stripe mutations in the schema (logged in — GraphQLite hides #[Logged] fields from anonymous introspection)"
    login
    gql '{ __type(name: "Mutation") { fields { name args { name type { name kind ofType { name } } } type { name ofType { name } } } } }' \
        | jq -r '.data.__type.fields[] | select(.name|startswith("stripeCheckout")) | "  \(.name)(\([.args[] | "\(.name): \(.type.name // .type.ofType.name)"] | join(", "))): \(.type.name // .type.ofType.name)"'
    for type in CheckoutStartResult CheckoutReturnResult CheckoutCancelResult; do
        printf '  %s { %s }\n' "$type" "$(gql "{ __type(name: \"$type\") { fields { name type { name ofType { name } } } } }" | jq -r '[.data.__type.fields[] | "\(.name): \(.type.name // .type.ofType.name)"] | join(", ")')"
    done
}

cmd_start() {
    local ui_mode="${1:-hosted}"
    say "stripeCheckoutStart (uiMode: $ui_mode)"
    login
    local basket_id start
    basket_id=$(basket_with_one_product)
    start=$(data "$(gql "mutation { stripeCheckoutStart(basketId: \"$basket_id\", confirmTermsAndConditions: true, returnUrl: \"$RETURN_URL\", cancelUrl: \"$CANCEL_URL\", uiMode: \"$ui_mode\") { contractId contractToken providerName orderNumber redirectUrl clientSecret renderMode } }")" stripeCheckoutStart)
    printf '%s\n' "$start" | jq .
    save_state "$(printf '%s' "$start" | jq -r .contractId)" "$(printf '%s' "$start" | jq -r .contractToken)" "$basket_id"
    note "contract + order are open (order NOT_FINISHED); saved to $STATE_FILE"
}

cmd_pay() {
    cmd_start hosted
    say "Now pay"
    note "1. open the redirectUrl above in a browser and pay with 4242 4242 4242 4242 (any future date, any CVC)"
    note "2. with a registered Stripe webhook the shop commits the order by itself (checkout.session.completed,"
    note "   payment_intent.succeeded, or payment_intent.amount_capturable_updated for manual capture) — check the"
    note "   order in the admin, or run '$(basename "$0") return <session_id>', which then only reports the state"
    note "3. without a webhook: Stripe sends the browser to ${RETURN_URL}&session_id=cs_test_... — copy the"
    note "   session_id and run: $(basename "$0") return cs_test_..."
}

cmd_return() {
    local session_id="${1:-}"
    [ -n "$session_id" ] || { echo "usage: return <checkoutSessionId> [contractId contractToken]" >&2; exit 1; }
    load_state "${2:-}" "${3:-}"
    say "stripeCheckoutReturn"
    login
    data "$(gql "mutation { stripeCheckoutReturn(contractId: \"$CONTRACT_ID\", contractToken: \"$CONTRACT_TOKEN\", checkoutSessionId: \"$session_id\") { status orderId orderNumber contractState } }")" stripeCheckoutReturn | jq .
}

cmd_cancel() {
    load_state "${1:-}" "${2:-}"
    say "stripeCheckoutCancel"
    login
    data "$(gql "mutation { stripeCheckoutCancel(contractId: \"$CONTRACT_ID\", contractToken: \"$CONTRACT_TOKEN\") { cancelled contractState } }")" stripeCheckoutCancel | jq .
}

cmd_wrong_token() {
    load_state "${1:-}" ""
    CONTRACT_TOKEN="0000000000000000000000000000dead"
    say "stripeCheckoutCancel with a wrong token (expected: refused)"
    login
    note "$(error_of "$(gql "mutation { stripeCheckoutCancel(contractId: \"$CONTRACT_ID\", contractToken: \"$CONTRACT_TOKEN\") { cancelled contractState } }")")"
}

cmd_guard() {
    say "core placeOrder on a basket that pays with Stripe (expected: refused, points at stripeCheckoutStart)"
    login
    local basket_id
    basket_id=$(basket_with_one_product)
    data "$(gql "mutation { basketSetDeliveryMethod(basketId: \"$basket_id\", deliveryMethodId: \"$DELIVERY_METHOD_ID\") { id } }")" basketSetDeliveryMethod.id >/dev/null
    data "$(gql "mutation { basketSetPayment(basketId: \"$basket_id\", paymentId: \"oe_payments_stripe_wallet\") { id } }")" basketSetPayment.id >/dev/null
    note "$(error_of "$(gql "mutation { placeOrder(basketId: \"$basket_id\", confirmTermsAndConditions: true) { id } }")")"
}

cmd_demo() {
    cmd_schema
    cmd_start hosted
    cmd_cancel
    cmd_start embedded
    cmd_wrong_token
    cmd_cancel
    cmd_guard
    say "Done. For a paid order run: $(basename "$0") pay"
}

cmd_raw() {
    [ -n "${1:-}" ] || { echo "usage: raw '<graphql document>'" >&2; exit 1; }
    login
    gql "$1" | jq .
}

need curl; need jq
case "${1:-}" in
    schema) cmd_schema ;;
    start) cmd_start "${2:-hosted}" ;;
    pay) cmd_pay ;;
    return) cmd_return "${2:-}" "${3:-}" "${4:-}" ;;
    cancel) cmd_cancel "${2:-}" "${3:-}" ;;
    wrong-token) cmd_wrong_token "${2:-}" ;;
    guard) cmd_guard ;;
    demo) cmd_demo ;;
    raw) cmd_raw "${2:-}" ;;
    -h|--help|help|"") usage ;;
    *) echo "unknown command: $1" >&2; usage >&2; exit 2 ;;
esac
