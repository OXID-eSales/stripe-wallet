"""Build the k6 capacity report: charts + self-contained HTML + step-summary md.

The k6 counterpart of :mod:`chart.render`. One command turns a finished ``k6 run``
into the same deliverable the Locust workflow produces:

    python -m chart.k6_render \
        --results ../results.json \
        --summary ../summary.json \
        --out ../k6-report \
        --xscale linear --yscale log10

Writes ``<out>/charts/*.png`` (the shared chart set), ``<out>/index.html`` (stats
table + inlined charts) and ``<out>/summary.md`` (for ``$GITHUB_STEP_SUMMARY``).
Best-effort by design: missing/partial inputs degrade to fewer artifacts, never a
crash, so the workflow can run it with ``continue-on-error`` after the load run.
"""
from __future__ import annotations

import argparse
import os
import sys
from typing import List, Optional, Tuple

from . import charts, embed
from .k6 import (
    DEFAULT_LATENCY_METRIC,
    build_report_html,
    parse_k6_stream,
    parse_k6_summary,
    summary_markdown,
    summary_rows,
)
from .parse import aggregate_by_users


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


def _render_charts(args, out_dir: str) -> List[str]:
    if not os.path.exists(args.results):
        print(f"k6 results not found: {args.results}", file=sys.stderr)
        return []
    with open(args.results) as handle:
        samples = parse_k6_stream(
            handle,
            latency_metric=args.latency_metric,
            bucket_seconds=args.bucket_seconds,
        )
    points = aggregate_by_users(samples)
    if not points:
        print(
            f"no usable samples in {args.results} (metric '{args.latency_metric}'?)"
            " — skipping charts",
            file=sys.stderr,
        )
        return []
    charts_dir = os.path.join(out_dir, "charts")
    # Single ramped run -> scatter (x is the swept variable), same as chart.render.
    written = charts.latency_charts(
        points, charts_dir, x_scale=args.xscale, y_scale=args.yscale, connect=False
    )
    written += charts.load_charts(
        points, charts_dir, x_scale=args.xscale, y_scale=args.yscale, connect=False
    )
    for path in written:
        print(f"wrote {path}")
    return written


def main(argv: Optional[List[str]] = None) -> int:
    args = build_arg_parser().parse_args(argv)
    out_dir = args.out
    os.makedirs(out_dir, exist_ok=True)

    chart_paths = _render_charts(args, out_dir)

    stat_rows: List[Tuple[str, str]] = []
    if args.summary and os.path.exists(args.summary):
        with open(args.summary) as handle:
            metrics = parse_k6_summary(handle.read())
        stat_rows = summary_rows(metrics)
    elif args.summary:
        print(f"k6 summary not found: {args.summary}", file=sys.stderr)

    # summary.md — appended to $GITHUB_STEP_SUMMARY by the workflow.
    with open(os.path.join(out_dir, "summary.md"), "w", encoding="utf-8") as handle:
        handle.write(summary_markdown(stat_rows))

    # index.html — stats table + inlined charts (reuses chart.embed).
    html = build_report_html(args.title, _param_rows(args.param), stat_rows)
    html = embed.embed(html, chart_paths)
    index_path = os.path.join(out_dir, "index.html")
    with open(index_path, "w", encoding="utf-8") as handle:
        handle.write(html)
    print(f"wrote {index_path} ({len(chart_paths)} chart(s) embedded)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
