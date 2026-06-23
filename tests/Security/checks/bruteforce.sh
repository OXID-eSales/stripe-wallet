#!/usr/bin/env bash
# Login brute-force throttling — AGGRESSIVE only. Fires a burst of bad logins and
# looks for throttling (429 / lockout / "too many"). med severity: reports a gap
# without breaking the default high budget (OXID core may not throttle by default).

check_login_bruteforce() {
    local i status throttled=0 body
    for i in $(seq 1 30); do
        status=$(status_of POST "${BASE_URL}/index.php" \
            --data-urlencode "lgn_usr=bruteforce_${i}@oxid-esales.dev" \
            --data-urlencode "lgn_pwd=wrong-${i}" \
            -d 'cl=account&fnc=login_noredirect')
        [ "$status" = "429" ] && throttled=$((throttled + 1))
    done
    body=$(http -X POST \
        --data-urlencode 'lgn_usr=bruteforce_final@oxid-esales.dev' \
        --data-urlencode 'lgn_pwd=wrong' \
        -d 'cl=account&fnc=login_noredirect' "${BASE_URL}/index.php" 2>/dev/null || echo "")
    # Keep the body-match specific — a bare "rate" matches innocuous page text
    # ("rating", "separate", …) and produces a false pass.
    if [ "$throttled" -gt 0 ]; then
        record BF-login med pass "Login throttling observed" "${throttled}/30 got 429"
    elif echo "$body" | grep -qiE 'too many (login|attempt)|account.{0,10}locked|try again later|rate[ -]?limit|throttl'; then
        record BF-login med pass "Login lockout message returned" "throttle/lockout text after 30 bad logins"
    else
        record BF-login med fail "No login brute-force throttling observed" "30 bad logins, none throttled"
    fi
}
