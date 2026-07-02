"""Build the k6 capacity report: charts + self-contained HTML + step-summary md.

The k6 counterpart of :mod:`chart.render`. One command turns a finished ``k6 run``
into the same deliverable the Locust workflow produces, plus the k6-only extras
(Web Vitals, saturation, per-scenario breakdown, threshold verdicts):

    python -m chart.k6_render \
        --results ../results.json \
        --summary ../summary.json \
        --out ../k6-report \
        --xscale linear --yscale log10

Writes ``<out>/charts/*.png`` (the shared chart set + a Web-Vitals chart),
``<out>/index.html`` (stat sections + inlined charts) and ``<out>/summary.md``
(for ``$GITHUB_STEP_SUMMARY``). Best-effort by design: missing/partial inputs
degrade to fewer artifacts, never a crash, so the workflow runs it with
``continue-on-error`` after the load run.
"""
from __future__ import annotations

import argparse
import os
import sys
from typing import List, Optional, Tuple

from . import charts, embed, plot
from .k6 import (
    DEFAULT_LATENCY_METRIC,
    WebVitalPoint,
    build_report_html,
    parse_k6_rich,
    parse_k6_summary,
    saturation_rows,
    scenario_rows,
    summary_rows,
    markdown_sections,
    threshold_rows,
    webvital_rows,
)
from .parse import aggregate_by_users

Section = Tuple[str, List[Tuple[str, str]]]


def build_arg_parser() -> argparse.ArgumentParser:
    from .plot import SCALES

    parser = argparse.ArgumentParser(
        prog="python -m chart.k6_render",
        description="Render the capacity report from one k6 run's JSON outputs.",
    )
    parser.add_argument("--results", required=True, help="path to k6 --out json=… NDJSON")
    parser.add_argument("--summary", default="", help="path to k6 --summary-export JSON")
    parser.add_argument("--out", default="k6-report", help="output directory")
    parser.add_argument("--xscale", default="linear", choices=list(SCALES))
    parser.add_argument("--yscale", default="log10", choices=list(SCALES))
    parser.add_argument("--latency-metric", default=DEFAULT_LATENCY_METRIC)
    parser.add_argument("--bucket-seconds", type=float, default=10.0)
    parser.add_argument("--title", default="k6 Load Test")
    parser.add_argument(
        "--param",
        action="append",
        default=[],
        metavar="LABEL=VALUE",
        help="run-parameter row for the report header (repeatable)",
    )
    return parser


def _param_rows(raw: List[str]) -> List[Tuple[str, str]]:
    rows: List[Tuple[str, str]] = []
    for item in raw:
        label, _, value = item.partition("=")
        rows.append((label.strip(), value.strip()))
    return rows


def _web_vital_chart(points: List[WebVitalPoint], out_dir: str, args) -> List[str]:
    """One chart: the ms-valued Web Vitals (p95) vs concurrent users."""
    if not any(p.lcp or p.fcp or p.ttfb or p.inp for p in points):
        return []
    charts_dir = os.path.join(out_dir, "charts")
    os.makedirs(charts_dir, exist_ok=True)
    path = plot.render_chart(
        points,
        x_field="users",
        x_label="concurrent users",
        x_scale=args.xscale,
        y_series=[
            plot.Series("lcp", "LCP"),
            plot.Series("fcp", "FCP"),
            plot.Series("ttfb", "TTFB"),
            plot.Series("inp", "INP"),
        ],
        y_label="web vital p95 (ms)",
        y_scale=args.yscale,
        out_path=os.path.join(charts_dir, "web-vitals-users.png"),
        title="Web Vitals (p95) vs concurrent users",
        connect=False,
    )
    print(f"wrote {path}")
    return [path]


def _render_charts(parsed, out_dir: str, args) -> List[str]:
    points = aggregate_by_users(parsed.samples)
    written: List[str] = []
    if points:
        charts_dir = os.path.join(out_dir, "charts")
        # Single ramped run -> scatter (x is the swept variable), as chart.render.
        written += charts.latency_charts(
            points, charts_dir, x_scale=args.xscale, y_scale=args.yscale, connect=False
        )
        written += charts.load_charts(
            points, charts_dir, x_scale=args.xscale, y_scale=args.yscale, connect=False
        )
        for path in written:
            print(f"wrote {path}")
    else:
        print(
            f"no usable samples (metric '{args.latency_metric}'?) — skipping capacity charts",
            file=sys.stderr,
        )
    written += _web_vital_chart(parsed.web_vitals, out_dir, args)
    return written


def main(argv: Optional[List[str]] = None) -> int:
    args = build_arg_parser().parse_args(argv)
    out_dir = args.out
    os.makedirs(out_dir, exist_ok=True)

    # One stream pass: primary samples + Web-Vitals series + per-scenario stats.
    parsed = parse_k6_rich(
        [], latency_metric=args.latency_metric, bucket_seconds=args.bucket_seconds
    )
    if os.path.exists(args.results):
        with open(args.results) as handle:
            parsed = parse_k6_rich(
                handle,
                latency_metric=args.latency_metric,
                bucket_seconds=args.bucket_seconds,
            )
    else:
        print(f"k6 results not found: {args.results}", file=sys.stderr)

    chart_paths = _render_charts(parsed, out_dir, args)

    metrics: dict = {}
    if args.summary and os.path.exists(args.summary):
        with open(args.summary) as handle:
            metrics = parse_k6_summary(handle.read())
    elif args.summary:
        print(f"k6 summary not found: {args.summary}", file=sys.stderr)

    # Stat sections: summary-derived tables + the stream-derived per-scenario one.
    stat_sections: List[Section] = [
        ("Aggregated statistics", summary_rows(metrics)),
        ("Web Vitals (browser UX)", webvital_rows(metrics)),
        ("Saturation & throughput", saturation_rows(metrics)),
        ("Per-scenario breakdown", scenario_rows(parsed.scenarios)),
        ("Threshold verdicts", threshold_rows(metrics)),
    ]

    # summary.md — appended to $GITHUB_STEP_SUMMARY by the workflow.
    with open(os.path.join(out_dir, "summary.md"), "w", encoding="utf-8") as handle:
        handle.write(markdown_sections(stat_sections))

    # index.html — run parameters + stat sections + inlined charts (chart.embed).
    sections: List[Section] = [("Run parameters", _param_rows(args.param))] + stat_sections
    html = build_report_html(args.title, sections)
    html = embed.embed(html, chart_paths)
    index_path = os.path.join(out_dir, "index.html")
    with open(index_path, "w", encoding="utf-8") as handle:
        handle.write(html)
    print(f"wrote {index_path} ({len(chart_paths)} chart(s) embedded)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
