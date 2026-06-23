#!/usr/bin/env bash
# Self-test for the pure severity gate. No shop / network needed — the CI workflow
# runs this before the pentest so a broken gate can't silently pass the security
# check (the chart_parse fail-fast lesson). Plain bash asserts, no bats dependency.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GATE="$HERE/gate.sh"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
fail=0

# assert_exit <expected-code> <budget> <description>  (findings already in $tmp)
assert_exit() {
    local exp="$1" budget="$2" desc="$3" got
    "$GATE" "$tmp" "$budget" >/dev/null 2>&1
    got=$?
    if [ "$got" = "$exp" ]; then
        echo "  ✓ $desc"
    else
        echo "  ✗ $desc (exit $got, expected $exp)"
        fail=1
    fi
}

echo "=== gate self-test ==="

printf 'F1|high|fail|x|\n' > "$tmp"
assert_exit 1 high "high fail breaches high budget"

printf 'F2|med|fail|x|\n' > "$tmp"
assert_exit 0 high "med fail is below high budget (no breach)"

printf 'F3|crit|fail|x|\n' > "$tmp"
assert_exit 1 high "crit fail breaches high budget"

printf 'F4|crit|pass|x|\nF5|high|skip|x|\n' > "$tmp"
assert_exit 0 high "pass + skip never breach"

printf 'F6|med|fail|x|\n' > "$tmp"
assert_exit 1 med "med fail breaches med budget"

printf 'F7|low|fail|x|\n' > "$tmp"
assert_exit 0 med "low fail is below med budget (no breach)"

: > "$tmp"
assert_exit 0 high "empty findings pass"

if [ "$fail" = 0 ]; then
    echo "gate self-test: PASS"
else
    echo "gate self-test: FAIL"
    exit 1
fi
