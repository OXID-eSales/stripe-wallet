#!/usr/bin/env bash
# Open-redirect probe (F14). Tries common redirect-param names on the return
# endpoints with an off-host target and inspects the Location header: if the shop
# bounces the browser to the attacker host, that is an open redirect.

check_open_redirect() {
    local evil="https://evil.example.test/" hit="" param endpoint location
    local endpoints=(
        "${BASE_URL}/index.php?cl=order"
        "${BASE_URL}/index.php?cl=payment"
        "${BASE_URL}/index.php?cl=start"
    )
    local params=(redirect return_url returnUrl sUrl url next continue)

    for endpoint in "${endpoints[@]}"; do
        for param in "${params[@]}"; do
            location=$(http -sI -o - -w '' "${endpoint}&${param}=${evil}" 2>/dev/null \
                | tr -d '\r' | awk -F': ' 'tolower($1)=="location"{print $2}' | tail -n1)
            case "$location" in
                http://evil.example.test/*|https://evil.example.test/*|//evil.example.test/*)
                    hit="${param} on ${endpoint}"
                    break 2
                    ;;
            esac
        done
    done

    if [ -n "$hit" ]; then
        record F14-redirect high fail "Open redirect to attacker host" "$hit"
    else
        record F14-redirect high pass "No off-host redirect on tested params" "tried ${#params[@]} params x ${#endpoints[@]} endpoints"
    fi
}
