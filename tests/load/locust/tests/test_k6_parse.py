"""Unit tests for the k6 -> Sample adapter + summary formatting + report HTML."""
import json

from chart.embed import embed
from chart.k6 import (
    build_report_html,
    parse_k6_rich,
    parse_k6_stream,
    parse_k6_summary,
    saturation_rows,
    scenario_rows,
    summary_markdown,
    summary_rows,
    threshold_rows,
    webvital_rows,
)
from chart.parse import aggregate_by_users


def _point(metric, t, value):
    return json.dumps(
        {"type": "Point", "metric": metric, "data": {"time": t, "value": value}}
    )


# Two time buckets (10s wide): t=…00Z -> bucket A (10 VUs), t=…11Z -> bucket B (20 VUs).
_STREAM = "\n".join([
    json.dumps({"type": "Metric", "metric": "vus", "data": {"type": "gauge"}}),
    _point("vus", "2026-06-12T10:00:00Z", 10),
    _point("browser_http_req_duration", "2026-06-12T10:00:01Z", 100),
    _point("browser_http_req_duration", "2026-06-12T10:00:02Z", 200),
    _point("browser_http_req_failed", "2026-06-12T10:00:03Z", 1),
    _point("browser_http_req_failed", "2026-06-12T10:00:04Z", 0),
    _point("vus", "2026-06-12T10:00:11Z", 20),
    _point("browser_http_req_duration", "2026-06-12T10:00:12Z", 300),
    _point("browser_http_req_duration", "2026-06-12T10:00:13Z", 500),
]) + "\n"


def test_parse_k6_stream_buckets_by_vus():
    samples = parse_k6_stream(_STREAM.splitlines(), bucket_seconds=10.0)
    assert [s.user_count for s in samples] == [10, 20]
    first = samples[0]
    assert first.p100 == 200.0           # slowest in bucket A
    assert first.rps == 2 / 10.0         # 2 latency points over a 10s bucket
    assert first.failures_per_s == 1 / 10.0  # one failed (value>0), one ok


def test_parse_k6_stream_drops_pre_spawn_and_empty():
    # A latency point before any vus reading lands in a 0-user bucket -> dropped.
    stream = "\n".join([
        _point("browser_http_req_duration", "2026-06-12T09:59:50Z", 90),
        _point("vus", "2026-06-12T10:00:00Z", 5),
        _point("browser_http_req_duration", "2026-06-12T10:00:01Z", 120),
    ])
    samples = parse_k6_stream(stream.splitlines(), bucket_seconds=10.0)
    assert [s.user_count for s in samples] == [5]


def test_aggregate_by_users_consumes_k6_samples():
    points = aggregate_by_users(parse_k6_stream(_STREAM.splitlines(), bucket_seconds=10.0))
    assert [p.users for p in points] == [10, 20]


def test_parse_k6_stream_ignores_metric_definitions_and_junk():
    stream = "\n".join([
        "not json",
        json.dumps({"type": "Metric", "metric": "vus", "data": {}}),
        _point("vus", "2026-06-12T10:00:00Z", 3),
        _point("browser_http_req_duration", "2026-06-12T10:00:01Z", 50),
    ])
    samples = parse_k6_stream(stream.splitlines(), bucket_seconds=10.0)
    assert len(samples) == 1
    assert samples[0].user_count == 3


_SUMMARY = json.dumps({
    "metrics": {
        "browser_http_req_duration": {"avg": 120.0, "p(95)": 300.0, "p(99)": 450.0, "max": 600.0},
        "checkout_success_rate": {"value": 0.93, "passes": 93, "fails": 7},
        "orders_created": {"count": 88, "rate": 1.47},
        "browser_http_req_failed": {"value": 0.02},
    }
})


def test_summary_rows_formats_trends_rates_counters():
    rows = dict(summary_rows(parse_k6_summary(_SUMMARY)))
    assert "p95 300" in rows["Browser HTTP request (ms)"]
    assert rows["Checkout success"] == "93.00% (93/100)"
    assert rows["Orders created"] == "88 (rate 1.47/s)"
    assert rows["Browser HTTP failed"] == "2.00%"


def test_parse_k6_summary_tolerates_garbage():
    assert parse_k6_summary("{ not valid") == {}
    assert summary_markdown([]).startswith("_No k6 summary")


def test_report_html_is_embeddable():
    html = build_report_html(
        "k6",
        [("Run parameters", [("Users", "100")]), ("Aggregated statistics", [("Orders created", "88")])],
    )
    assert html.endswith("</body></html>")
    assert "Run parameters" in html and "Aggregated statistics" in html
    # The embed contract: charts land inside the real body, tables preserved.
    out = embed(html, [])
    assert out.count("data-charts-embed") == 1
    assert "Orders created" in out


def test_build_report_html_skips_empty_sections():
    html = build_report_html("k6", [("Web Vitals (browser UX)", []), ("Stats", [("Orders", "5")])])
    assert "Web Vitals" not in html   # empty section omitted
    assert "Stats" in html and "Orders" in html


# ── rich parser: web vitals + per-scenario breakdown ──────────────────────────

def _tagged(metric, t, value, scenario=None):
    data = {"time": t, "value": value}
    if scenario:
        data["tags"] = {"scenario": scenario}
    return json.dumps({"type": "Point", "metric": metric, "data": data})


_RICH = "\n".join([
    _point("vus", "2026-06-12T10:00:00Z", 10),
    _tagged("browser_http_req_duration", "2026-06-12T10:00:01Z", 100, "happy_path"),
    _tagged("browser_http_req_duration", "2026-06-12T10:00:02Z", 300, "happy_path"),
    _tagged("browser_http_req_duration", "2026-06-12T10:00:03Z", 500, "threeds"),
    _tagged("browser_http_req_failed", "2026-06-12T10:00:03Z", 1, "threeds"),
    _point("browser_web_vital_lcp", "2026-06-12T10:00:02Z", 2200),
    _point("browser_web_vital_fcp", "2026-06-12T10:00:02Z", 900),
])


def test_parse_k6_rich_collects_samples_webvitals_scenarios():
    parsed = parse_k6_rich(_RICH.splitlines(), bucket_seconds=10.0)
    assert [s.user_count for s in parsed.samples] == [10]
    # Web vitals bucketed at the 10-VU level.
    assert len(parsed.web_vitals) == 1
    wv = parsed.web_vitals[0]
    assert wv.users == 10 and wv.lcp == 2200.0 and wv.fcp == 900.0
    # Per-scenario: happy_path has 2 reqs / 0 fail, threeds 1 req / 1 fail.
    by_name = {s.scenario: s for s in parsed.scenarios}
    assert by_name["happy_path"].requests == 2 and by_name["happy_path"].failures == 0
    assert by_name["threeds"].requests == 1 and by_name["threeds"].error_pct == 100.0
    # Sorted by request volume.
    assert parsed.scenarios[0].scenario == "happy_path"


_RICH_SUMMARY = json.dumps({
    "metrics": {
        "browser_web_vital_lcp": {"p(75)": 2100.0, "p(95)": 3400.0},
        "browser_web_vital_cls": {"p(75)": 0.04, "p(95)": 0.12},
        "dropped_iterations": {"count": 12, "rate": 0.4},
        "iterations": {"count": 240, "rate": 4.0},
        "vus_max": {"value": 200},
        "checks": {"passes": 480, "fails": 5, "value": 0.9897},
        "data_received": {"count": 5242880},
        "checkout_duration": {"thresholds": {"p(95)<60000": {"ok": True}}},
        "checkout_success_rate": {"thresholds": {"rate>0.90": {"ok": False}}},
    }
})


def test_webvital_rows_p75_and_cls_precision():
    rows = dict(webvital_rows(parse_k6_summary(_RICH_SUMMARY)))
    assert rows["LCP — Largest Contentful Paint (ms)"] == "p75 2100 · p95 3400"
    assert rows["CLS — Cumulative Layout Shift"] == "p75 0.040 · p95 0.120"


def test_saturation_rows_dropped_iterations_and_bytes():
    rows = dict(saturation_rows(parse_k6_summary(_RICH_SUMMARY)))
    assert rows["Dropped iterations (overload)"] == "12 (rate 0.40/s)"
    assert rows["Completed journeys (iterations)"] == "240 (rate 4.00/s)"
    assert rows["Peak VUs"] == "200"
    assert rows["Data received"] == "5.0 MB"
    assert "98.97%" in rows["Checks passed"]


def test_threshold_rows_pass_and_fail():
    rows = dict(threshold_rows(parse_k6_summary(_RICH_SUMMARY)))
    assert rows["checkout_duration p(95)<60000"] == "✅ PASS"
    assert rows["checkout_success_rate rate>0.90"] == "❌ FAIL"
