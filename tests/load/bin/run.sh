#!/usr/bin/env bash
#
# Run k6 load tests locally (browser mode with Chromium).
#
# Usage:
#   ./bin/run.sh                          # dry-run, all scenarios
#   ./bin/run.sh --full                   # 100 VU/min, 10 min
#   ./bin/run.sh --baseline               # 10 VU/min, 2 min
#   ./bin/run.sh --endurance              # 100 VU/min, 30 min
#   ./bin/run.sh --scenario happy_path    # single scenario, dry-run
#   ./bin/run.sh --vus 50 --duration 5    # custom params
#   ./bin/run.sh --headed                 # show browser (no headless)
#
# Config: tests/load/.env (copy from .env.dist)
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOAD_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
K6_CONFIG="$LOAD_DIR/k6.config.js"
RESULTS_DIR="$LOAD_DIR/results"

# ─── Load .env ────────────────────────────────────────────────────
# IMPORTANT: Custom vars use LOAD_ prefix, NOT K6_ prefix.
# k6 interprets K6_DURATION, K6_VUS etc. as built-in overrides.
if [ -f "$LOAD_DIR/.env" ]; then
  while IFS='=' read -r key value; do
    [ -z "$key" ] && continue
    case "$key" in \#*) continue ;; esac
    export "$key=$value"
  done < "$LOAD_DIR/.env"
fi

# ─── Defaults ─────────────────────────────────────────────────────
PRESET=""
export LOAD_DRY_RUN="${LOAD_DRY_RUN:-true}"
export LOAD_SCENARIO="${LOAD_SCENARIO:-all}"
export LOAD_TARGET_VUS="${LOAD_TARGET_VUS:-100}"
export LOAD_DURATION="${LOAD_DURATION:-10}"
export LOAD_RAMP_UP="${LOAD_RAMP_UP:-2}"

# ─── Parse arguments ─────────────────────────────────────────────
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run)    export LOAD_DRY_RUN="true"; shift ;;
    --full)       PRESET="full"; shift ;;
    --baseline)   PRESET="baseline"; shift ;;
    --endurance)  PRESET="endurance"; shift ;;
    --headed)     export K6_BROWSER_HEADLESS="false"; shift ;;
    --scenario)   export LOAD_SCENARIO="$2"; shift 2 ;;
    --vus)        export LOAD_TARGET_VUS="$2"; export LOAD_DRY_RUN="false"; shift 2 ;;
    --duration)   export LOAD_DURATION="$2"; shift 2 ;;
    --ramp-up)    export LOAD_RAMP_UP="$2"; shift 2 ;;
    --help|-h)    sed -n '3,16p' "$0" | sed 's/^# \?//'; exit 0 ;;
    *)            echo "Unknown: $1" >&2; exit 1 ;;
  esac
done

# ─── Apply presets ───────────────────────────────────────────────
case "$PRESET" in
  baseline)  export LOAD_TARGET_VUS=10  LOAD_DURATION=2  LOAD_RAMP_UP=1 LOAD_DRY_RUN=false ;;
  full)      export LOAD_TARGET_VUS=100 LOAD_DURATION=10 LOAD_RAMP_UP=2 LOAD_DRY_RUN=false ;;
  endurance) export LOAD_TARGET_VUS=100 LOAD_DURATION=30 LOAD_RAMP_UP=3 LOAD_DRY_RUN=false ;;
esac

# ─── Find or install k6 ─────────────────────────────────────────
if command -v k6 >/dev/null 2>&1; then
  K6_BIN="k6"
elif [ -x "$LOAD_DIR/bin/k6" ]; then
  K6_BIN="$LOAD_DIR/bin/k6"
else
  echo "Installing k6 v0.54.0..."
  ARCH=$(uname -m)
  case "$ARCH" in
    x86_64)  K6_ARCH="linux-amd64" ;;
    aarch64) K6_ARCH="linux-arm64" ;;
    *)       echo "Unsupported: $ARCH" >&2; exit 1 ;;
  esac
  curl -sSL "https://github.com/grafana/k6/releases/download/v0.54.0/k6-v0.54.0-${K6_ARCH}.tar.gz" \
    | tar xz --strip-components=1 -C "$LOAD_DIR/bin/" "k6-v0.54.0-${K6_ARCH}/k6"
  chmod +x "$LOAD_DIR/bin/k6"
  K6_BIN="$LOAD_DIR/bin/k6"
fi

# ─── Print config ───────────────────────────────────────────────
echo "═══════════════════════════════════════════════════"
echo "  k6 Load Test — Stripe Payment (Browser Mode)"
echo "═══════════════════════════════════════════════════"
echo ""
echo "  Shop URL:    $K6_BASE_URL"
echo "  Scenario:    $LOAD_SCENARIO"
if [ "$LOAD_DRY_RUN" = "true" ]; then
  echo "  Mode:        DRY RUN (1 VU, 1 iteration)"
else
  echo "  VUs/min:     $LOAD_TARGET_VUS"
  echo "  Duration:    ${LOAD_DURATION}m (+ ${LOAD_RAMP_UP}m ramp-up + 1m ramp-down)"
fi
echo "  Headless:    ${K6_BROWSER_HEADLESS:-true}"
echo "  Preset:      ${PRESET:-custom}"
echo ""
echo "═══════════════════════════════════════════════════"
echo ""

# ─── Run k6 ─────────────────────────────────────────────────────
mkdir -p "$RESULTS_DIR"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
RESULT_LOG="$RESULTS_DIR/${PRESET:-custom}-${LOAD_SCENARIO}-${TIMESTAMP}.log"

cd "$LOAD_DIR"
$K6_BIN run \
  --summary-trend-stats="avg,min,med,max,p(90),p(95),p(99)" \
  "$K6_CONFIG" \
  2>&1 | tee "$RESULT_LOG"

echo ""
echo "Log: $RESULT_LOG"
