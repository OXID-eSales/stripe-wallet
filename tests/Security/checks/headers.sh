#!/usr/bin/env bash
# Security response headers + banner leakage. Low severity: these report but do
# not break the default `high` budget — they surface hardening gaps without
# turning the gate red on a header policy difference.

check_security_headers() {
    local headers
    headers=$(header_of "${BASE_URL}/index.php?cl=start" | tr -d '\r')
    if [ -z "$headers" ]; then
        record HDR-headers low skip "Start page headers not reachable" "transport error"
        return
    fi

    # HSTS only meaningful over HTTPS.
    case "$BASE_URL" in
        https://*)
            if echo "$headers" | grep -qi '^Strict-Transport-Security:'; then
                record HDR-hsts low pass "HSTS header present" ""
            else
                record HDR-hsts low fail "Missing Strict-Transport-Security" "no HSTS over HTTPS"
            fi
            ;;
    esac

    if echo "$headers" | grep -qi '^X-Content-Type-Options:.*nosniff'; then
        record HDR-nosniff low pass "X-Content-Type-Options: nosniff present" ""
    else
        record HDR-nosniff low fail "Missing X-Content-Type-Options: nosniff" ""
    fi

    if echo "$headers" | grep -qiE '^X-Frame-Options:|^Content-Security-Policy:.*frame-ancestors'; then
        record HDR-frame low pass "Clickjacking guard present" "X-Frame-Options or CSP frame-ancestors"
    else
        record HDR-frame low fail "Missing clickjacking guard" "no X-Frame-Options / CSP frame-ancestors"
    fi
}

check_server_banner() {
    local headers banner powered
    headers=$(header_of "${BASE_URL}/index.php?cl=start" | tr -d '\r')
    [ -n "$headers" ] || { record HDR-banner low skip "Headers not reachable" "transport error"; return; }

    banner=$(echo "$headers" | awk -F': ' 'tolower($1)=="server"{print $2}' | tail -n1)
    powered=$(echo "$headers" | awk -F': ' 'tolower($1)=="x-powered-by"{print $2}' | tail -n1)

    if [ -n "$powered" ] || echo "$banner" | grep -qE '[0-9]+\.[0-9]+'; then
        record HDR-banner low fail "Server/version banner leaked" "Server='${banner}' X-Powered-By='${powered}'"
    else
        record HDR-banner low pass "No version banner leaked" "Server='${banner:-<none>}'"
    fi
}
