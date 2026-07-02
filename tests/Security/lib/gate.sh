#!/usr/bin/env bash
# Pure severity gate — the thresholds.py analogue. Reads a findings file
# (id|severity|status|title|evidence), prints a BREACH line for every FAILED
# finding at or above the budget severity, and exits 1 if any breached, else 0.
#
# Usage: gate.sh <findings-file> [fail-on-severity]   (default budget: high)
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=common.sh
source "$HERE/common.sh"

gate() {
    local findings="$1" budget="${2:-high}"
    [ -f "$findings" ] || { echo "gate: no findings file at $findings" >&2; return 0; }

    local budget_rank breached=0 id severity status title evidence rank
    budget_rank="$(severity_rank "$budget")"

    while IFS='|' read -r id severity status title evidence; do
        [ -n "${id:-}" ] || continue
        [ "$status" = "fail" ] || continue
        rank="$(severity_rank "$severity")"
        if [ "$rank" -ge "$budget_rank" ]; then
            printf 'BREACH severity=%s id=%s title="%s"\n' "$severity" "$id" "$title"
            breached=1
        fi
    done < "$findings"

    return "$breached"
}

# Run only when executed directly (not when sourced).
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    gate "${1:?usage: gate.sh <findings-file> [fail-on-severity]}" "${2:-high}"
fi
