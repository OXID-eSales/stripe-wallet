#!/usr/bin/env bash
# Transport (http/status_of/header_of) + finding recorder (record).
# Sourced by pentest.sh before the check files. Requires $FINDINGS (a writable
# file path). Honours: PENTEST_INSECURE_TLS (any value -> -k), PENTEST_TIMEOUT.

# http <curl args...> — curl with the harness defaults applied. Callers add their
# own -X/-d/-o/-w. Returns curl's exit status; guard call sites with `|| echo 000`.
http() {
    curl -sS --max-time "${PENTEST_TIMEOUT:-15}" ${PENTEST_INSECURE_TLS:+-k} "$@"
}

# status_of <method> <url> [extra curl args...] -> prints the HTTP status code
# (000 on a transport error, never fails the caller). curl -w already prints 000
# on connection failure, so we capture rather than add a `|| echo 000` (which
# would double to "000000" and defeat the 000-skip branches in the checks).
status_of() {
    local method="$1" url="$2" out
    shift 2
    out=$(http -o /dev/null -w '%{http_code}' -X "$method" "$@" "$url" 2>/dev/null)
    echo "${out:-000}"
}

# header_of <url> -> prints the response headers (best-effort; empty on error).
header_of() {
    http -sIL -o - -w '' "$1" 2>/dev/null || true
}

# record <id> <severity> <status> <title> [evidence]
#   severity: info|low|med|high|crit   status: pass|fail|skip
# Appends a pipe-delimited line to $FINDINGS and echoes a human progress line.
# Pipes/newlines in title/evidence are flattened so the field format stays intact.
record() {
    local id="$1" severity="$2" status="$3" title="$4" evidence="${5:-}"
    title="${title//|/ }"; title="${title//$'\n'/ }"
    evidence="${evidence//|/ }"; evidence="${evidence//$'\n'/ }"
    printf '%s|%s|%s|%s|%s\n' "$id" "$severity" "$status" "$title" "$evidence" >> "$FINDINGS"

    local mark
    case "$status" in
        pass) mark="✓" ;;
        fail) mark="✗" ;;
        skip) mark="–" ;;
        *)    mark="?" ;;
    esac
    printf '  %s [%s/%s] %s%s\n' "$mark" "$id" "$severity" "$title" "${evidence:+ — $evidence}"
}
