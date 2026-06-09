# Locust heavy-load tests (OXID Stripe)

A Locust load profile for the Stripe shop, triggered **manually** from GitHub
Actions. Mirrors the [VBWD-platform `Heavy Load`](https://github.com/VBWD-platform/vbwd-platform)
harness (auth pre-mint not needed here; load-shed tolerance, typed threshold
gating, and matplotlib capacity charts are) — but adapted to drive the
already-deployed OXID shop instead of booting a stack in CI.

> **Scope.** Locust is HTTP-level. It exercises page rendering, session + basket,
> and the Stripe `createCheckoutSession` endpoint. It does **not** drive the
> Stripe hosted-checkout redirect or the 3DS browser flow — that is what the
> browser-mode **k6** harness (`tests/load/k6.config.js`, workflow
> `Load Test — Stripe Payment`) is for. The two are complementary.

## Run in CI (manual)

1. GitHub → **Actions** → **Load Test (Locust)** → **Run workflow**.
2. Tune inputs (defaults: 50 VU, 5/s spawn, 2 min, `all` scenarios, p95 ≤ 1500 ms,
   error ≤ 1 %).
3. The job **refuses to run unless Stripe is in test mode** (a hard safety
   pre-flight over SSH) — we never load-test live keys.
4. When it finishes, download the **`locust-report-<run>`** artifact: a
   self-contained `load-report/index.html` (capacity charts embedded), the
   per-endpoint CSVs, the raw `locust-output.txt`, and `charts/*.png`.

### Inputs at a glance

| Input | Default | Notes |
|---|---|---|
| `target_url` | `https://pay1.oxid.dev` | shop base URL (no trailing slash) |
| `users` | `50` | concurrent virtual users |
| `spawn_rate` | `5` | new users/sec (ramps the count up) |
| `duration` | `2m` | run time (`30s`, `5m`, …) |
| `scenarios` | `all` | `all` / `browse` / `checkout` |
| `fail_p95_ms` | `1500` | p95 budget — breach fails the job |
| `fail_pct_error` | `1.0` | % error budget — breach fails the job |
| `chart_xscale` / `chart_yscale` | `linear` / `log10` | capacity-chart axes |

## Scenarios

| Class | Weight | What it loads |
|---|---|---|
| `AnonymousBrowse` | 6 | start page, search, product detail, category list |
| `CheckoutFlow` | 1 | login (seeded user) → add to basket → basket/user/payment/order renders → `POST createCheckoutSession` |

Discovery of a product `anid` / category `cnid` is scraped from the start/search
HTML at `test_start` (override with `LOAD_PRODUCT_ANID` / `LOAD_CATEGORY_CNID`).
OXID's frequent redirects and graceful load-shedding (`301/302/429/503`) are
tolerated as *not* failures; a checkout precondition we cannot satisfy over raw
HTTP (login wall / AGB / address) is tolerated too, so the error rate reflects
real server faults — not harness limits.

## Thresholds

`thresholds.py` is a **pure** evaluator (no Locust import) consumed by the
locustfile's `quitting` listener: a breach prints one
`BREACH category=… actual=… budget=…` line and sets a non-zero exit, which fails
the job. Budgets: `error_pct ≤ 1%`, `p95 ≤ 1500 ms`, `p99 ≤ 3000 ms`,
`throughput ≥ 5 rps` (the p95/error budgets are overridable per run).

## Capacity charts

A single ramped run already sweeps the user count 0→N, so its
`stats_stats_history.csv` holds a *range* of load levels. `chart/render.py` plots
them — throughput vs users (saturation plateau = ceiling), users vs latency
(p50/p95/p99), error rate vs users, full-page render vs users — then
`chart/embed.py` base64-inlines the PNGs into the report.

## Run locally

```bash
cd tests/load/locust
python -m venv .venv && . .venv/bin/activate
pip install locust==2.31.8 -r requirements-chart.txt

# Single ramped run (against a test-mode shop):
locust -f locustfile.py --host https://pay1.oxid.dev \
  --headless --users 50 --spawn-rate 5 --run-time 2m \
  --csv load-report/stats --html load-report/index.html

# Charts from that run, then embed them into the HTML:
python -m chart.render --history load-report/stats_stats_history.csv \
  --out load-report/charts --xscale linear --yscale log10
python -m chart.embed --index load-report/index.html --charts load-report/charts/*.png
```

Multi-level **capacity sweep** (runs Locust at each level, writes a reproducible
`sweep.csv`, then charts it; optional MySQL transaction-rate dimension):

```bash
python -m chart.sweep --host https://pay1.oxid.dev \
  --users 10,25,50,100,200 --run-time 1m --scenarios all \
  --mysql-dsn mysql://root:root@127.0.0.1:3306/example --out ./load-charts
```

## Unit tests

The pure modules (`thresholds`, `chart.parse`, `chart.embed`) are unit-tested:

```bash
cd tests/load/locust && pip install pytest && python -m pytest tests/
```

## Seeded data

The workflow seeds 200 login-able users (`loadtest_user_001..200@oxid-esales.dev`,
password `useruser`) and sets high stock before the run (idempotent). The
`CheckoutFlow` scenario logs in as these.
