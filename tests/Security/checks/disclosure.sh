#!/usr/bin/env bash
# Information-disclosure probes: VCS metadata, dependency manifests, config
# source, and verbose error pages.

# /.git/HEAD must not be served (a served repo leaks full source + history).
check_disclosure_git() {
    local status body
    status=$(status_of GET "${BASE_URL}/.git/HEAD")
    if [ "$status" = "200" ]; then
        body=$(http "${BASE_URL}/.git/HEAD" 2>/dev/null || echo "")
        if echo "$body" | grep -qE '^ref:'; then
            record INFO-git high fail "Exposed .git repository" ".git/HEAD served"
            return
        fi
    fi
    record INFO-git high pass ".git not exposed" "$status"
}

# composer.json should not be web-served (leaks dependency versions / paths).
check_disclosure_composer() {
    local status body
    status=$(status_of GET "${BASE_URL}/composer.json")
    if [ "$status" = "200" ]; then
        body=$(http "${BASE_URL}/composer.json" 2>/dev/null || echo "")
        if echo "$body" | grep -qE '"(require|name|autoload)"'; then
            record INFO-composer med fail "composer.json web-served" "dependency manifest readable"
            return
        fi
    fi
    record INFO-composer med pass "composer.json not exposed" "$status"
}

# config.inc.php must execute (empty/forbidden), never reveal credentials.
check_disclosure_config() {
    local body
    body=$(http "${BASE_URL}/config.inc.php" 2>/dev/null || echo "")
    if echo "$body" | grep -qiE 'dbPwd|dbUser|<\?php|sShopURL'; then
        record INFO-config crit fail "config.inc.php source disclosed" "credentials/source visible"
    else
        record INFO-config crit pass "config.inc.php not disclosed" "no source/credentials in response"
    fi
}

# A bad request must not return a stack trace / absolute paths / debug output.
check_disclosure_debug() {
    local body
    body=$(http "${BASE_URL}/index.php?cl=__no_such_class_'\"<x>" 2>/dev/null || echo "")
    if echo "$body" | grep -qiE 'Stack trace|Fatal error|Uncaught|/var/www/|on line [0-9]+'; then
        record INFO-debug med fail "Verbose error / stack trace leaked" "debug output on bad request"
    else
        record INFO-debug med pass "No verbose error output" "errors suppressed"
    fi
}
