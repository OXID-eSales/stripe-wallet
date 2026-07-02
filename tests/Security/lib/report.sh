#!/usr/bin/env bash
# Render the findings file into report artifacts. Pure: reads $findings, writes
# files. Sourced by pentest.sh (severity_rank already available via common.sh).

# render_md <findings> <out.md> [base_url] [profile]
render_md() {
    local findings="$1" out="$2" base_url="${3:-}" profile="${4:-}"
    local total pass fail skip
    total=$(awk 'NF' "$findings" 2>/dev/null | wc -l | tr -d ' ')
    pass=$(awk -F'|' '$3=="pass"' "$findings" 2>/dev/null | wc -l | tr -d ' ')
    fail=$(awk -F'|' '$3=="fail"' "$findings" 2>/dev/null | wc -l | tr -d ' ')
    skip=$(awk -F'|' '$3=="skip"' "$findings" 2>/dev/null | wc -l | tr -d ' ')

    {
        echo "# Stripe module — black-box pentest findings"
        echo
        echo "| | |"
        echo "|--|--|"
        echo "| Target | \`${base_url}\` |"
        echo "| Profile | \`${profile}\` |"
        echo "| Findings | ${total} (${pass} pass · ${fail} fail · ${skip} skip) |"
        echo
        echo "| ID | Severity | Status | Title | Evidence |"
        echo "|----|----------|--------|-------|----------|"
        awk -F'|' 'NF{printf "| %s | %s | %s | %s | %s |\n", $1, $2, $3, $4, $5}' "$findings"
    } > "$out"
}

# render_json <findings> <out.json> — requires jq; no-op (with a note) without it.
render_json() {
    local findings="$1" out="$2"
    if ! command -v jq >/dev/null 2>&1; then
        echo "report: jq not found — skipping findings.json"
        return 0
    fi
    jq -R -s '
        split("\n")
        | map(select(length > 0) | split("|")
              | {id: .[0], severity: .[1], status: .[2], title: .[3], evidence: .[4]})
    ' "$findings" > "$out"
}
