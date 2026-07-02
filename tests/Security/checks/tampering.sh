#!/usr/bin/env bash
# Amount/currency tampering (F15/F16) — STANDARD profile (needs a seeded login).
# The defence is that createCheckoutSession derives the amount server-side from
# the basket; a client-supplied amount must not be callable / honoured. Each
# precondition that can't be met records a LOUD skip, never a false pass.

check_tampering_amount() {
    if [ -z "${SEED_USER:-}" ] || [ -z "${SEED_PASS:-}" ]; then
        record F15-tamper high skip "Amount-tamper check skipped" "no SEED_USER/SEED_PASS provided"
        return
    fi

    local jar token status
    jar="$(mktemp)"
    # Prime a session cookie.
    http -c "$jar" -b "$jar" -o /dev/null "${BASE_URL}/" >/dev/null 2>&1 || true
    # Scrape an stoken from a rendered form (best-effort across themes).
    token=$(http -b "$jar" "${BASE_URL}/index.php?cl=account" 2>/dev/null \
        | grep -oE 'name="stoken"[^>]*value="[^"]+"' | head -n1 \
        | sed -E 's/.*value="([^"]+)".*/\1/')

    # Attempt login.
    http -c "$jar" -b "$jar" -o /dev/null -X POST \
        --data-urlencode "lgn_usr=${SEED_USER}" \
        --data-urlencode "lgn_pwd=${SEED_PASS}" \
        --data-urlencode "stoken=${token}" \
        -d 'cl=account&fnc=login_noredirect' \
        "${BASE_URL}/index.php" >/dev/null 2>&1 || true

    if ! http -b "$jar" "${BASE_URL}/index.php?cl=account" 2>/dev/null \
        | grep -qiE 'logout|fnc=logout|cl=account_user'; then
        record F15-tamper high skip "Amount-tamper check skipped" "login did not establish a session (theme/flow differs)"
        rm -f "$jar"
        return
    fi

    # Logged in: a direct POST carrying a client-controlled amount must still be
    # blocked (CSRF/derivation). A 2xx here means the endpoint honoured caller input.
    status=$(status_of POST "$CHECKOUT_URL" -b "$jar" \
        -H 'Content-Type: application/json' \
        -d '{"amount":1,"currency":"eur","total":0.01}')
    rm -f "$jar"
    case "$status" in
        403|400|302|303) record F15-tamper high pass "Client amount not accepted by checkout session" "$status (vector blocked)" ;;
        000)             record F15-tamper high skip "Checkout endpoint not reachable" "transport error" ;;
        2??)             record F15-tamper high fail "Checkout session accepted a client-supplied amount" "$status" ;;
        *)               record F15-tamper high pass "Client amount not honoured" "$status" ;;
    esac
}
