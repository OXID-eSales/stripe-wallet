r"""Capacity sweep: run the Locust harness at several load levels, then chart it.

    python -m chart.sweep \
        --host https://pay1.oxid.dev \
        --users 10,25,50,100,200 \
        --spawn-rate-ratio 0.1 --run-time 2m \
        --scenarios all \
        --mysql-dsn mysql://root:root@127.0.0.1:3306/example \   # optional
        --out ./load-charts/

Writes ``<out>/sweep.csv`` (the reproducible measurement) plus the chart set.
Plotting is split from measurement: rendering reads ``sweep.csv`` back, so chart
tweaks never re-run the load.
"""
from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
import time
from typing import List, Optional

from . import charts, dbstat
from .parse import RunStats, SweepPoint, parse_locust_stats

# Scenario preset -> Locust user classes. Mirrors the load-test workflow's case
# statement; kept here so the sweep selects the same scenario subsets.
PRESET_CLASSES = {
    "all": [],
    "browse": ["AnonymousBrowse"],
    "checkout": ["CheckoutFlow"],
}

_DURATION_UNITS = {"s": 1, "m": 60, "h": 3600}


def parse_duration_seconds(text: str) -> int:
    """``"2m"`` -> 120, ``"30s"`` -> 30, ``"90"`` -> 90 (bare = seconds)."""
    match = re.fullmatch(r"\s*(\d+)\s*([smh]?)\s*", text.lower())
    if not match:
        raise ValueError(f"unparseable duration {text!r}")
    return int(match.group(1)) * _DURATION_UNITS[match.group(2) or "s"]


def scenario_classes(preset: str) -> List[str]:
    if preset not in PRESET_CLASSES:
        raise ValueError(
            f"unknown scenario preset {preset!r}; expected one of {sorted(PRESET_CLASSES)}"
        )
    return list(PRESET_CLASSES[preset])


def build_locust_argv(
    *,
    locustfile: str,
    host: str,
    users: int,
    spawn_rate: float,
    run_time: str,
    csv_prefix: str,
    html_path: str,
    classes: List[str],
) -> List[str]:
    """Assemble the headless Locust command for one level (single source of truth)."""
    argv = [
        "locust",
        "-f",
        locustfile,
        "--host",
        host,
        "--headless",
        "--users",
        str(users),
        "--spawn-rate",
        str(spawn_rate),
        "--run-time",
        run_time,
        "--csv",
        csv_prefix,
        "--html",
        html_path,
    ]
    argv.extend(classes)
    return argv


def spawn_rate_for(users: int, ratio: float) -> float:
    """Ramped spawn rate (>=1/s) — never a one-second thundering herd."""
    return max(1.0, round(users * ratio, 2))


def _read_run_stats(csv_prefix: str) -> Optional[RunStats]:
    stats_path = f"{csv_prefix}_stats.csv"
    if not os.path.exists(stats_path):
        return None
    with open(stats_path) as handle:
        try:
            return parse_locust_stats(handle.read())
        except ValueError:
            return None


def run_level(
    *,
    users: int,
    host: str,
    locustfile: str,
    run_time: str,
    spawn_ratio: float,
    classes: List[str],
    out_dir: str,
    mysql_dsn: Optional[str],
    run_command=subprocess.run,
) -> Optional[SweepPoint]:
    """Run one load level and return its SweepPoint (None if it produced no stats).

    ``run_command`` is injected so the orchestration is unit-testable without
    actually shelling out to Locust.
    """
    level_dir = os.path.join(out_dir, "runs", str(users))
    os.makedirs(level_dir, exist_ok=True)
    csv_prefix = os.path.join(level_dir, "stats")

    argv = build_locust_argv(
        locustfile=locustfile,
        host=host,
        users=users,
        spawn_rate=spawn_rate_for(users, spawn_ratio),
        run_time=run_time,
        csv_prefix=csv_prefix,
        html_path=os.path.join(level_dir, "index.html"),
        classes=classes,
    )

    before = sample_started = None
    if mysql_dsn:
        before = dbstat.sample_db_counters(mysql_dsn)
        sample_started = time.monotonic()

    print(f"\n=== {users} VU ===\n$ {' '.join(argv)}", flush=True)
    run_command(argv, check=False)

    db_tps = None
    if mysql_dsn and before is not None:
        elapsed = time.monotonic() - sample_started
        db_tps = dbstat.compute_db_tps(before, dbstat.sample_db_counters(mysql_dsn), elapsed)

    stats = _read_run_stats(csv_prefix)
    if stats is None:
        print(f"  ! {users} VU produced no stats — skipping this level", flush=True)
        return None

    return SweepPoint(
        users=users,
        rps=stats.rps,
        p50=stats.p50,
        p95=stats.p95,
        p99=stats.p99,
        error_pct=stats.error_pct,
        db_tps=db_tps,
        p100=stats.p100,
    )


def _parse_users(text: str) -> List[int]:
    levels = [int(token) for token in text.split(",") if token.strip()]
    if not levels:
        raise ValueError("--users must list at least one load level")
    return levels


def plot_scales():
    from .plot import SCALES  # local import keeps matplotlib off the import path

    return list(SCALES)


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="python -m chart.sweep",
        description="Sweep the Locust harness across load levels and chart the result.",
    )
    parser.add_argument("--host", default="https://pay1.oxid.dev")
    parser.add_argument("--users", default="10,25,50,100,200", help="comma-separated VU levels")
    parser.add_argument("--spawn-rate-ratio", type=float, default=0.1)
    parser.add_argument("--run-time", default="2m")
    parser.add_argument("--scenarios", default="all", choices=sorted(PRESET_CLASSES))
    parser.add_argument("--locustfile", default="locustfile.py")
    parser.add_argument("--mysql-dsn", default=None, help="optional; enables the DB chart")
    parser.add_argument("--out", default="load-charts")
    parser.add_argument("--xscale", default="linear", choices=plot_scales())
    parser.add_argument("--yscale", default="log10", choices=plot_scales(), help="latency-axis scale")
    return parser


def main(argv: Optional[List[str]] = None) -> int:
    args = build_arg_parser().parse_args(argv)
    levels = _parse_users(args.users)
    classes = scenario_classes(args.scenarios)
    os.makedirs(args.out, exist_ok=True)

    points: List[SweepPoint] = []
    for users in levels:
        point = run_level(
            users=users,
            host=args.host,
            locustfile=args.locustfile,
            run_time=args.run_time,
            spawn_ratio=args.spawn_rate_ratio,
            classes=classes,
            out_dir=args.out,
            mysql_dsn=args.mysql_dsn,
        )
        if point is not None:
            points.append(point)

    if not points:
        print("no load level produced stats — nothing to chart", file=sys.stderr)
        return 1

    from .parse import write_sweep_csv

    sweep_csv = os.path.join(args.out, "sweep.csv")
    write_sweep_csv(points, sweep_csv)
    print(f"\nwrote {sweep_csv} ({len(points)} levels)")

    written = charts.full_chart_set(points, args.out, x_scale=args.xscale, y_scale=args.yscale)
    for path in written:
        print(f"wrote {path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
