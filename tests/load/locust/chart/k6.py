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


def summary_markdown(rows: List[Tuple[str, str]]) -> str:
    """Render (label, value) rows as a GitHub-flavoured Markdown table."""
    if not rows:
        return "_No k6 summary metrics were exported._\n"
    lines = ["| Metric | Value |", "|--------|-------|"]
    lines += [f"| {label} | {value} |" for label, value in rows]
    return "\n".join(lines) + "\n"


# ── Self-contained HTML report (base for chart.embed) ─────────────────────────

def build_report_html(
    title: str,
    param_rows: List[Tuple[str, str]],
    stat_rows: List[Tuple[str, str]],
) -> str:
    """Minimal self-contained HTML report; ``chart.embed`` inlines the PNGs.

    Ends with the ``</body></html>`` pair :func:`chart.embed.embed` anchors on, so
    the same embed path used for the Locust report works unchanged here.
    """
    def table(rows: List[Tuple[str, str]]) -> str:
        body = "".join(f"<tr><td>{key}</td><td>{value}</td></tr>" for key, value in rows)
        return (
            '<table style="border-collapse:collapse;font:14px sans-serif">'
            '<tbody>' + body + '</tbody></table>'
        )

    style = (
        "td{border:1px solid #ddd;padding:.3rem .6rem}"
        "h1{font:700 22px sans-serif}h2{font:700 18px sans-serif}"
        "body{margin:0;padding:1rem 2rem;color:#222}"
    )
    return (
        "<!doctype html><html><head><meta charset=\"utf-8\">"
        f"<title>{title}</title><style>{style}</style></head><body>"
        f"<h1>{title}</h1>"
        "<h2>Run parameters</h2>" + table(param_rows) +
        "<h2>Aggregated statistics</h2>" + table(stat_rows) +
        "</body></html>"
    )
