"""Adapt k6 output to the shared chart tooling — same charts as Locust, for k6.

Two inputs, both produced by a single ``k6 run``:

* the streaming NDJSON (``--out json=results.json``) -> a time series we bucket
  into the very same :class:`~chart.parse.Sample` shape the Locust history parser
  yields, so ``chart.charts`` renders the identical capacity-chart set for k6.
* the summary export (``--summary-export=summary.json``) -> the aggregate stats
  table for the GitHub step summary and the HTML report header.

k6's *browser* executor reports per-request latency as ``browser_http_req_duration``
(plain protocol runs use ``http_req_duration``); pass ``latency_metric`` to override.

Pure functions only (no argparse, no matplotlib) so the bucketing + formatting is
unit-testable in isolation, exactly like :mod:`chart.parse`.
"""
from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import datetime
from typing import Dict, Iterable, List, Optional, Tuple

from .parse import Sample

# Default k6 metric names. Browser mode emits the ``browser_*`` family; a plain
# protocol-level test would pass http_req_duration / http_req_failed instead.
DEFAULT_LATENCY_METRIC = "browser_http_req_duration"
DEFAULT_FAILED_METRIC = "browser_http_req_failed"
DEFAULT_VUS_METRIC = "vus"


def _parse_time(raw: str) -> Optional[float]:
    """k6 RFC3339 timestamp -> epoch seconds. None on anything unparseable.

    k6 emits nanosecond precision (9 fractional digits); ``fromisoformat`` accepts
    at most 6, so the fraction is trimmed. ``Z`` is normalised to ``+00:00``.
    """
    if not raw:
        return None
    text = raw.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    if "." in text:
        head, _, tail = text.partition(".")
        frac, offset = tail, ""
        for sign in ("+", "-"):
            idx = tail.find(sign)
            if idx != -1:
                frac, offset = tail[:idx], tail[idx:]
                break
        text = f"{head}.{frac[:6]}{offset}"
    try:
        return datetime.fromisoformat(text).timestamp()
    except ValueError:
        return None


def _percentile(values: List[float], q: float) -> float:
    """Linear-interpolated percentile of an unsorted list (q in 0..100)."""
    if not values:
        return 0.0
    ordered = sorted(values)
    if len(ordered) == 1:
        return ordered[0]
    rank = q / 100.0 * (len(ordered) - 1)
    low = int(rank)
    high = min(low + 1, len(ordered) - 1)
    return ordered[low] + (ordered[high] - ordered[low]) * (rank - low)


@dataclass
class _Bucket:
    latencies: List[float] = field(default_factory=list)
    failures: int = 0
    requests: int = 0
    vus: List[float] = field(default_factory=list)


def parse_k6_stream(
    lines: Iterable[str],
    *,
    latency_metric: str = DEFAULT_LATENCY_METRIC,
    failed_metric: str = DEFAULT_FAILED_METRIC,
    vus_metric: str = DEFAULT_VUS_METRIC,
    bucket_seconds: float = 10.0,
) -> List[Sample]:
    """Fold k6's NDJSON point stream into per-time-bucket :class:`Sample` rows.

    The active VU count (a gauge) becomes the load axis, mirroring Locust's
    ``User Count``; latency percentiles, throughput and failure rate are computed
    per bucket. Buckets before the first VU reading (pre-spawn) or with no latency
    samples are dropped, so the load axis never starts at 0 — same contract as
    :func:`chart.parse.parse_history`.
    """
    buckets: Dict[int, _Bucket] = {}

    def bucket_for(t: float) -> _Bucket:
        key = int(t // bucket_seconds)
        return buckets.setdefault(key, _Bucket())

    for line in lines:
        line = line.strip()
        if not line or line[0] != "{":
            continue
        try:
            record = json.loads(line)
        except ValueError:
            continue
        if record.get("type") != "Point":
            continue
        data = record.get("data") or {}
        t = _parse_time(data.get("time", ""))
        value = data.get("value")
        if t is None or value is None:
            continue
        metric = record.get("metric")
        if metric == latency_metric:
            bucket = bucket_for(t)
            bucket.latencies.append(float(value))
            bucket.requests += 1
        elif metric == failed_metric:
            if float(value) > 0:
                bucket_for(t).failures += 1
        elif metric == vus_metric:
            bucket_for(t).vus.append(float(value))

    samples: List[Sample] = []
    last_vus = 0.0
    for key in sorted(buckets):
        bucket = buckets[key]
        if bucket.vus:
            last_vus = sum(bucket.vus) / len(bucket.vus)
        users = int(round(last_vus))
        if users <= 0 or not bucket.latencies:
            continue
        samples.append(
            Sample(
                user_count=users,
                rps=bucket.requests / bucket_seconds,
                p50=_percentile(bucket.latencies, 50),
                p95=_percentile(bucket.latencies, 95),
                p99=_percentile(bucket.latencies, 99),
                failures_per_s=bucket.failures / bucket_seconds,
                p100=max(bucket.latencies),
            )
        )
    return samples


# ── Rich single pass: web-vitals series + per-scenario breakdown ──────────────

# Web Vitals the k6 browser module auto-collects. The four ms-valued ones are
# chartable on a shared axis; CLS is unitless (table only).
WEB_VITAL_MS = {
    "browser_web_vital_lcp": "lcp",
    "browser_web_vital_fcp": "fcp",
    "browser_web_vital_ttfb": "ttfb",
    "browser_web_vital_inp": "inp",
}


@dataclass
class WebVitalPoint:
    """p95 of each ms-valued Web Vital at one concurrent-user level."""

    users: int
    lcp: float = 0.0
    fcp: float = 0.0
    ttfb: float = 0.0
    inp: float = 0.0


@dataclass
class ScenarioStats:
    """Per-scenario aggregate of the latency metric (from the ``scenario`` tag)."""

    scenario: str
    requests: int
    failures: int
    p50: float
    p95: float
    p99: float

    @property
    def error_pct(self) -> float:
        if not self.requests:
            return 0.0
        return self.failures / self.requests * 100.0


@dataclass
class K6Parsed:
    """Everything one stream pass yields: primary samples + extras."""

    samples: List[Sample]
    web_vitals: List[WebVitalPoint]
    scenarios: List[ScenarioStats]


@dataclass
class _ScenAcc:
    requests: int = 0
    failures: int = 0
    latencies: List[float] = field(default_factory=list)


def _resolve_users(vus_buckets: Dict[int, List[float]], keys: Iterable[int]) -> Dict[int, int]:
    """Map every bucket key to the active VU count, carrying the gauge forward."""
    resolved: Dict[int, int] = {}
    last = 0.0
    for key in sorted(set(vus_buckets) | set(keys)):
        readings = vus_buckets.get(key)
        if readings:
            last = sum(readings) / len(readings)
        resolved[key] = int(round(last))
    return resolved


def parse_k6_rich(
    lines: Iterable[str],
    *,
    latency_metric: str = DEFAULT_LATENCY_METRIC,
    failed_metric: str = DEFAULT_FAILED_METRIC,
    vus_metric: str = DEFAULT_VUS_METRIC,
    bucket_seconds: float = 10.0,
) -> K6Parsed:
    """Single pass over the k6 NDJSON yielding primary samples, the Web-Vitals
    series (p95 by user level) and the per-scenario latency breakdown.

    One pass keeps a multi-GB ``results.json`` readable once, not once per chart.
    """
    buckets: Dict[int, _Bucket] = {}
    vus_buckets: Dict[int, List[float]] = {}
    wv_buckets: Dict[int, Dict[str, List[float]]] = {}
    scenarios: Dict[str, _ScenAcc] = {}

    for line in lines:
        line = line.strip()
        if not line or line[0] != "{":
            continue
        try:
            record = json.loads(line)
        except ValueError:
            continue
        if record.get("type") != "Point":
            continue
        data = record.get("data") or {}
        t = _parse_time(data.get("time", ""))
        value = data.get("value")
        if t is None or value is None:
            continue
        value = float(value)
        key = int(t // bucket_seconds)
        metric = record.get("metric")
        tags = data.get("tags") or {}
        scenario = tags.get("scenario")

        if metric == latency_metric:
            bucket = buckets.setdefault(key, _Bucket())
            bucket.latencies.append(value)
            bucket.requests += 1
            if scenario:
                scenarios.setdefault(scenario, _ScenAcc()).requests += 1
                scenarios[scenario].latencies.append(value)
        elif metric == failed_metric:
            if value > 0:
                buckets.setdefault(key, _Bucket()).failures += 1
                if scenario:
                    scenarios.setdefault(scenario, _ScenAcc()).failures += 1
        elif metric == vus_metric:
            vus_buckets.setdefault(key, []).append(value)
        elif metric in WEB_VITAL_MS:
            wv_buckets.setdefault(key, {}).setdefault(metric, []).append(value)

    resolved = _resolve_users(vus_buckets, list(buckets) + list(wv_buckets))

    samples: List[Sample] = []
    for key in sorted(buckets):
        bucket = buckets[key]
        users = resolved.get(key, 0)
        if users <= 0 or not bucket.latencies:
            continue
        samples.append(
            Sample(
                user_count=users,
                rps=bucket.requests / bucket_seconds,
                p50=_percentile(bucket.latencies, 50),
                p95=_percentile(bucket.latencies, 95),
                p99=_percentile(bucket.latencies, 99),
                failures_per_s=bucket.failures / bucket_seconds,
                p100=max(bucket.latencies),
            )
        )

    web_vitals = _web_vital_points(wv_buckets, resolved)
    scenario_stats = _scenario_stats(scenarios)
    return K6Parsed(samples=samples, web_vitals=web_vitals, scenarios=scenario_stats)


def _web_vital_points(
    wv_buckets: Dict[int, Dict[str, List[float]]],
    resolved: Dict[int, int],
) -> List[WebVitalPoint]:
    by_users: Dict[int, Dict[str, List[float]]] = {}
    for key, metric_map in wv_buckets.items():
        users = resolved.get(key, 0)
        if users <= 0:
            continue
        dest = by_users.setdefault(users, {})
        for metric, values in metric_map.items():
            dest.setdefault(metric, []).extend(values)

    points: List[WebVitalPoint] = []
    for users in sorted(by_users):
        collected = by_users[users]

        def p95(metric: str) -> float:
            values = collected.get(metric)
            return _percentile(values, 95) if values else 0.0

        points.append(
            WebVitalPoint(
                users=users,
                lcp=p95("browser_web_vital_lcp"),
                fcp=p95("browser_web_vital_fcp"),
                ttfb=p95("browser_web_vital_ttfb"),
                inp=p95("browser_web_vital_inp"),
            )
        )
    return points


def _scenario_stats(scenarios: Dict[str, _ScenAcc]) -> List[ScenarioStats]:
    stats = [
        ScenarioStats(
            scenario=name,
            requests=acc.requests,
            failures=acc.failures,
            p50=_percentile(acc.latencies, 50),
            p95=_percentile(acc.latencies, 95),
            p99=_percentile(acc.latencies, 99),
        )
        for name, acc in scenarios.items()
    ]
    return sorted(stats, key=lambda s: s.requests, reverse=True)


# ── Summary export (statistics table) ─────────────────────────────────────────

# Curated metric -> label. Only metrics present in the export are rendered, so a
# scenario subset (or a future metric rename) degrades gracefully to fewer rows.
_SUMMARY_TRENDS: List[Tuple[str, str]] = [
    ("browser_http_req_duration", "Browser HTTP request (ms)"),
    ("http_req_duration", "HTTP request (ms)"),
    ("iteration_duration", "Iteration (ms)"),
    ("checkout_duration", "Checkout flow (ms)"),
]
_SUMMARY_RATES: List[Tuple[str, str]] = [
    ("checkout_success_rate", "Checkout success"),
    ("contract_state_valid", "Contract state valid"),
    ("browser_http_req_failed", "Browser HTTP failed"),
    ("http_req_failed", "HTTP failed"),
]
_SUMMARY_COUNTERS: List[Tuple[str, str]] = [
    ("orders_created", "Orders created"),
    ("stripe_api_errors", "Stripe API errors"),
    ("http_reqs", "HTTP requests"),
]


def parse_k6_summary(json_text: str) -> Dict[str, dict]:
    """Return the ``metrics`` map from a ``--summary-export`` JSON document."""
    try:
        document = json.loads(json_text)
    except ValueError:
        return {}
    metrics = document.get("metrics")
    return metrics if isinstance(metrics, dict) else {}


def summary_rows(metrics: Dict[str, dict]) -> List[Tuple[str, str]]:
    """Flatten selected k6 metrics into (label, value) rows for table rendering."""
    rows: List[Tuple[str, str]] = []
    for key, label in _SUMMARY_TRENDS:
        metric = metrics.get(key)
        if metric:
            rows.append((
                label,
                f"p95 {metric.get('p(95)', 0):.0f} · p99 {metric.get('p(99)', 0):.0f} · "
                f"avg {metric.get('avg', 0):.0f} · max {metric.get('max', 0):.0f}",
            ))
    for key, label in _SUMMARY_RATES:
        metric = metrics.get(key)
        if not metric:
            continue
        fraction = metric.get("value", metric.get("rate"))
        if fraction is None:
            continue
        text = f"{float(fraction) * 100:.2f}%"
        if "passes" in metric and "fails" in metric:
            total = int(metric["passes"]) + int(metric["fails"])
            text += f" ({int(metric['passes'])}/{total})"
        rows.append((label, text))
    for key, label in _SUMMARY_COUNTERS:
        metric = metrics.get(key)
        if metric:
            rows.append((label, f"{int(metric.get('count', 0))} (rate {metric.get('rate', 0):.2f}/s)"))
    return rows


# Web Vitals: p75 is the field-data convention (Core Web Vitals); p95 catches the
# tail. Needs ``p(75)`` in --summary-trend-stats (the workflow sets it).
_SUMMARY_WEBVITALS_MS: List[Tuple[str, str]] = [
    ("browser_web_vital_lcp", "LCP — Largest Contentful Paint (ms)"),
    ("browser_web_vital_fcp", "FCP — First Contentful Paint (ms)"),
    ("browser_web_vital_ttfb", "TTFB — Time To First Byte (ms)"),
    ("browser_web_vital_inp", "INP — Interaction to Next Paint (ms)"),
]


def webvital_rows(metrics: Dict[str, dict]) -> List[Tuple[str, str]]:
    """Web-Vitals (p75 · p95) rows; CLS rendered with finer precision (unitless)."""
    rows: List[Tuple[str, str]] = []
    for key, label in _SUMMARY_WEBVITALS_MS:
        metric = metrics.get(key)
        if metric:
            rows.append((label, f"p75 {metric.get('p(75)', 0):.0f} · p95 {metric.get('p(95)', 0):.0f}"))
    cls = metrics.get("browser_web_vital_cls")
    if cls:
        rows.append((
            "CLS — Cumulative Layout Shift",
            f"p75 {cls.get('p(75)', 0):.3f} · p95 {cls.get('p(95)', 0):.3f}",
        ))
    return rows


def _human_bytes(value: float) -> str:
    number = float(value)
    for unit in ("B", "KB", "MB", "GB"):
        if number < 1024 or unit == "GB":
            return f"{number:.1f} {unit}"
        number /= 1024
    return f"{number:.1f} GB"


def saturation_rows(metrics: Dict[str, dict]) -> List[Tuple[str, str]]:
    """Capacity-ceiling signals: dropped iterations, journey throughput, peak VUs,
    overall check pass-rate, and bytes transferred."""
    rows: List[Tuple[str, str]] = []
    dropped = metrics.get("dropped_iterations")
    if dropped is not None:
        rows.append((
            "Dropped iterations (overload)",
            f"{int(dropped.get('count', 0))} (rate {dropped.get('rate', 0):.2f}/s)",
        ))
    iterations = metrics.get("iterations")
    if iterations:
        rows.append((
            "Completed journeys (iterations)",
            f"{int(iterations.get('count', 0))} (rate {iterations.get('rate', 0):.2f}/s)",
        ))
    peak = metrics.get("vus_max")
    if peak:
        rows.append(("Peak VUs", f"{int(peak.get('value', peak.get('max', 0)))}"))
    checks = metrics.get("checks")
    if checks:
        fraction = checks.get("value", checks.get("rate"))
        if fraction is not None:
            text = f"{float(fraction) * 100:.2f}%"
            if "passes" in checks and "fails" in checks:
                total = int(checks["passes"]) + int(checks["fails"])
                text += f" ({int(checks['passes'])}/{total})"
            rows.append(("Checks passed", text))
    for key, label in (("data_received", "Data received"), ("data_sent", "Data sent")):
        metric = metrics.get(key)
        if metric:
            rows.append((label, _human_bytes(metric.get("count", 0))))
    return rows


def threshold_rows(metrics: Dict[str, dict]) -> List[Tuple[str, str]]:
    """Each configured threshold with its PASS/FAIL verdict (from the export)."""
    rows: List[Tuple[str, str]] = []
    for name, metric in metrics.items():
        thresholds = metric.get("thresholds") if isinstance(metric, dict) else None
        if not isinstance(thresholds, dict):
            continue
        for expr, result in thresholds.items():
            ok = result.get("ok", False) if isinstance(result, dict) else bool(result)
            rows.append((f"{name} {expr}", "✅ PASS" if ok else "❌ FAIL"))
    return rows


def scenario_rows(scenarios: List[ScenarioStats]) -> List[Tuple[str, str]]:
    """One row per scenario: requests, error %, and latency percentiles."""
    return [
        (
            scenario.scenario,
            f"reqs {scenario.requests} · err {scenario.error_pct:.2f}% · "
            f"p50 {scenario.p50:.0f} · p95 {scenario.p95:.0f} · p99 {scenario.p99:.0f} ms",
        )
        for scenario in scenarios
    ]


def summary_markdown(rows: List[Tuple[str, str]]) -> str:
    """Render (label, value) rows as a GitHub-flavoured Markdown table."""
    if not rows:
        return "_No k6 summary metrics were exported._\n"
    lines = ["| Metric | Value |", "|--------|-------|"]
    lines += [f"| {label} | {value} |" for label, value in rows]
    return "\n".join(lines) + "\n"


def markdown_sections(sections: List[Tuple[str, List[Tuple[str, str]]]]) -> str:
    """Render titled (heading, rows) sections as stacked Markdown tables."""
    parts: List[str] = []
    for heading, rows in sections:
        if not rows:
            continue
        parts.append(f"### {heading}\n\n")
        parts.append("| Metric | Value |\n|--------|-------|\n")
        parts.extend(f"| {label} | {value} |\n" for label, value in rows)
        parts.append("\n")
    if not parts:
        return "_No k6 summary metrics were exported._\n"
    return "".join(parts)


# ── Self-contained HTML report (base for chart.embed) ─────────────────────────

def build_report_html(
    title: str,
    sections: List[Tuple[str, List[Tuple[str, str]]]],
) -> str:
    """Minimal self-contained HTML report; ``chart.embed`` inlines the PNGs.

    ``sections`` is an ordered list of ``(heading, rows)``; empty sections are
    skipped. Ends with the ``</body></html>`` pair :func:`chart.embed.embed`
    anchors on, so the same embed path used for the Locust report works here.
    """
    def table(rows: List[Tuple[str, str]]) -> str:
        body = "".join(f"<tr><td>{key}</td><td>{value}</td></tr>" for key, value in rows)
        return (
            '<table style="border-collapse:collapse;font:14px sans-serif;margin-bottom:1rem">'
            '<tbody>' + body + '</tbody></table>'
        )

    style = (
        "td{border:1px solid #ddd;padding:.3rem .6rem}"
        "h1{font:700 22px sans-serif}h2{font:700 18px sans-serif}"
        "body{margin:0;padding:1rem 2rem;color:#222}"
    )
    chunks = [
        "<!doctype html><html><head><meta charset=\"utf-8\">",
        f"<title>{title}</title><style>{style}</style></head><body>",
        f"<h1>{title}</h1>",
    ]
    for heading, rows in sections:
        if rows:
            chunks.append(f"<h2>{heading}</h2>{table(rows)}")
    chunks.append("</body></html>")
    return "".join(chunks)
