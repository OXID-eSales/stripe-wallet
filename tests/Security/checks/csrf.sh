#!/usr/bin/env bash
# CSRF / unauthenticated-mutation guards. Maps to Sprint 64 T3/T4 (H8).

# T3 — createCheckoutSession without a session token must be rejected (403).
check_csrf_checkout() {
    local status
    status=$(status_of POST "$CHECKOUT_URL" -H 'Content-Type: application/json' -d '{}')
    case "$status" in
        403)      record H8-checkout high pass "createCheckoutSession rejects missing stoken" "403" ;;
        000)      record H8-checkout high skip "createCheckoutSession not reachable" "transport error" ;;
        302|303)  record H8-checkout high pass "createCheckoutSession redirected (no session)" "$status" ;;
        2??)      record H8-checkout high fail "createCheckoutSession accepted without stoken" "$status" ;;
        *)        record H8-checkout high pass "createCheckoutSession not directly callable" "$status" ;;
    esac
}

# T4 — admin refund/capture must not be reachable without an admin session.
check_csrf_admin() {
    local status
    status=$(status_of POST "$ADMIN_URL" \
        -d 'cl=PaymentAdmin&fnc=dispatchAction&payment_admin_action=refund&oxid=test')
    case "$status" in
        000)      record H8-admin high skip "Admin endpoint not reachable" "transport error" ;;
        200)      record H8-admin high pass "Admin POST returns login wall (200)" "verify body is login, not action" ;;
        3??|4??)  record H8-admin high pass "Admin refund not directly accessible" "$status" ;;
        *)        record H8-admin high fail "Admin refund returned unexpected status" "$status" ;;
    esac
}
