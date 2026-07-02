"""Chart a single existing run's ``stats_history.csv`` — no load is executed.

This is what the load-test workflow calls: every manual run already produces a
ramped ``stats_history.csv`` (user count climbs 0->N over the spawn window), so a
single run already contains a *range* of load levels to plot. Cheap (pure re-read
of a CSV) and safe to run with ``if: always()`` after Locust.

    python -m chart.render \
        --history load-report/stats_stats_history.csv \
        --out load-report/charts --xscale linear --yscale log10
"""
from __future__ import annotations

import argparse
import os
import sys
from typing import List, Optional

from . import charts
from .parse import aggregate_by_users, parse_history


def build_arg_parser() -> argparse.ArgumentParser:
    from .plot import SCALES

    parser = argparse.ArgumentParser(
        prog="python -m chart.render",
        description="Render latency charts from one Locust run's stats_history.csv.",
    )
    parser.add_argument("--history", required=True, help="path to *_stats_history.csv")
    parser.add_argument("--out", default="load-report/charts")
    parser.add_argument("--xscale", default="linear", choices=list(SCALES))
    parser.add_argument("--yscale", default="log10", choices=list(SCALES))
    return parser


def main(argv: Optional[List[str]] = None) -> int:
    args = build_arg_parser().parse_args(argv)

    if not os.path.exists(args.history):
        print(f"history CSV not found: {args.history}", file=sys.stderr)
        return 1

    with open(args.history) as handle:
        samples = parse_history(handle.read())

    points = aggregate_by_users(samples)
    if not points:
        print(f"no usable samples in {args.history} — nothing to chart", file=sys.stderr)
        return 1

    # A single ramped run: load-keyed charts are scatter (connect=False) since x
    # is the swept variable.
    written = charts.latency_charts(
        points, args.out, x_scale=args.xscale, y_scale=args.yscale, connect=False
    )
    written += charts.load_charts(
        points, args.out, x_scale=args.xscale, y_scale=args.yscale, connect=False
    )
    for path in written:
        print(f"wrote {path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
