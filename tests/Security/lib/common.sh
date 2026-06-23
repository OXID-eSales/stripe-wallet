#!/usr/bin/env bash
# Shared helpers for the pentest harness. Pure functions only — no I/O, no `set`,
# no top-level state, so this can be sourced by any lib without side effects.

# severity_rank <severity> -> numeric rank (higher = worse). Unknown -> 0 (info).
severity_rank() {
    case "$1" in
        crit) echo 4 ;;
        high) echo 3 ;;
        med)  echo 2 ;;
        low)  echo 1 ;;
        *)    echo 0 ;;
    esac
}
