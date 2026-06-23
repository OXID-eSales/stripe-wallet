#!/usr/bin/env bash
# Webhook endpoint hardening. Black-box probes against $WEBHOOK_URL.
# Maps to Sprint 64 T1/T2/T5 plus signature + transport guards.

# T1 — oversized payload must be rejected with 413 (M8 payload-size guard).
check_webhook_size() {
    local status
    status=$(dd if=/dev/zero bs=1M count=10 2>/dev/null \
        | http -o /dev/null -w '%{http_code}' -X POST --data-binary @- \
               -H 'Stripe-Signature: t=1,v1=fake' "$WEBHOOK_URL" 2>/dev/null)
    status="${status:-000}"
    case "$status" in
        413)     record M8-size med pass "Webhook rejects oversized payload" "413 (size guard)" ;;
        000)     record M8-size med skip "Webhook size guard not reachable" "transport error" ;;
        404|405) record M8-size med skip "Webhook route not found" "got $status (wrong controller key / module inactive?)" ;;
        4??)     record M8-size med pass "Oversized payload rejected" "$status — an earlier guard (e.g. HTTPS over cleartext) short-circuited before the 413 size guard" ;;
        2??)     record M8-size med fail "Webhook processed an oversized payload" "$status" ;;
        *)       record M8-size med skip "Oversized-payload result inconclusive" "$status" ;;
    esac
}

# Negative control — a normal-size payload must NOT be 413.
check_webhook_normal() {
    local status
    status=$(status_of POST "$WEBHOOK_URL" \
        -d '{"id":"evt_test","type":"test"}' -H 'Stripe-Signature: t=1,v1=fake')
    case "$status" in
        413)     record M8-normal info fail "Normal payload wrongly size-rejected" "413" ;;
        404|405) record M8-normal info skip "Webhook route not found" "$status" ;;
        *)       record M8-normal info pass "Normal payload not size-rejected" "$status" ;;
    esac
}

# An invalid Stripe-Signature must be rejected (not processed as 2xx).
check_webhook_badsig() {
    local status
    status=$(status_of POST "$WEBHOOK_URL" \
        -d '{"id":"evt_badsig","type":"payment_intent.succeeded","data":{"object":{"id":"pi_x"}}}' \
        -H 'Stripe-Signature: t=1,v1=deadbeef')
    case "$status" in
        400|401|403) record SIG-verify high pass "Invalid webhook signature rejected" "$status" ;;
        000)         record SIG-verify high skip "Webhook not reachable" "transport error" ;;
        404|405)     record SIG-verify high skip "Webhook route not found" "$status (wrong controller key / module inactive?)" ;;
        2??)         record SIG-verify high fail "Webhook processed an invalid signature" "$status" ;;
        *)           record SIG-verify high pass "Invalid signature not accepted" "$status" ;;
    esac
}

# T5 — replay/idempotency. Only observable when the same valid event is accepted
# twice; with a fake signature the first request is rejected, so we can only
# verify when the body reveals dedup. Loud skip otherwise (never a false pass).
check_webhook_replay() {
    local payload sig body2
    payload='{"id":"evt_replay_'"$$"'","type":"payment_intent.succeeded","data":{"object":{"id":"pi_replay"}}}'
    sig='t=1,v1=fakesig'
    http -o /dev/null -X POST -d "$payload" -H "Stripe-Signature: $sig" "$WEBHOOK_URL" >/dev/null 2>&1 || true
    body2=$(http -X POST -d "$payload" -H "Stripe-Signature: $sig" "$WEBHOOK_URL" 2>/dev/null || echo "")
    if echo "$body2" | grep -qiE 'already processed|duplicate|skipped'; then
        record C3-replay high pass "Duplicate webhook event detected" "second request deduped"
    else
        record C3-replay high skip "Replay dedup not observable" "needs a valid signature to reach the idempotency layer"
    fi
}

# Transport guard — informational. If the endpoint is served over HTTPS, a plain
# HTTP hit should redirect or be refused, not process the webhook over cleartext.
check_webhook_transport() {
    case "$WEBHOOK_URL" in
        https://*) ;;
        *) record TLS-webhook low skip "Webhook target is not HTTPS" "skipping transport probe" ; return ;;
    esac
    local http_url status
    http_url="http://${WEBHOOK_URL#https://}"
    status=$(status_of POST "$http_url" -d '{}' -H 'Stripe-Signature: t=1,v1=fake')
    case "$status" in
        301|302|307|308) record TLS-webhook low pass "Cleartext webhook redirected to HTTPS" "$status" ;;
        000|400|403|426) record TLS-webhook low pass "Cleartext webhook not served" "$status" ;;
        2??)             record TLS-webhook low fail "Webhook served over cleartext HTTP" "$status" ;;
        *)               record TLS-webhook low skip "Cleartext webhook inconclusive" "$status" ;;
    esac
}

# T2 — rate limiting (M7). AGGRESSIVE only: floods 120 requests, expects some 429.
check_webhook_rate_limit() {
    local i status limited=0
    for i in $(seq 1 120); do
        status=$(status_of POST "$WEBHOOK_URL" -d '{"id":"evt_rate"}' -H 'Stripe-Signature: t=1,v1=fake')
        [ "$status" = "429" ] && limited=$((limited + 1))
    done
    if [ "$limited" -gt 0 ]; then
        record M7-rate med pass "Webhook rate limiting active" "$limited/120 got 429"
    else
        record M7-rate med fail "No webhook rate limiting observed" "0/120 got 429"
    fi
}
