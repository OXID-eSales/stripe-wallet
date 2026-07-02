"""Pure parsers for Locust CSV output and our own sweep CSV.

No matplotlib, no I/O — every function takes CSV *text* and returns dataclasses,
so the parsing logic is unit-testable in isolation and can't silently rot when a
Locust version shifts a column. Columns are looked up **by header name**, never
by position, because Locust's column order varies across releases.
"""
from __future__ import annotations

import csv
import io
from dataclasses import dataclass, fields
from typing import Dict, List, Optional

# Locust's aggregate row labels its Name column with this sentinel.
AGGREGATED = "Aggregated"


def _to_float(value: Optional[str]) -> float:
    """Best-effort float — Locust writes "N/A" / "" for empty percentile cells."""
    if value is None:
        return 0.0
    try:
        return float(value)
    except (TypeError, ValueError):
        return 0.0


@dataclass
class RunStats:
    """The "Aggregated" row of a single run's ``*_stats.csv``."""

    request_count: int
    failure_count: int
    rps: float
    p50: float
    p95: float
    p99: float
    p100: float = 0.0  # the slowest request = full-page-render proxy

    @property
    def error_pct(self) -> float:
        if not self.request_count:
            return 0.0
        return self.failure_count / self.request_count * 100.0


@dataclass
class Sample:
    """One timestamped row of the aggregated ``*_stats_history.csv`` series."""

    user_count: int
    rps: float
    p50: float
    p95: float
    p99: float
    failures_per_s: float = 0.0  # instantaneous failure rate (``Failures/s`` col)
    p100: float = 0.0  # the slowest request at this tick


@dataclass
class SweepPoint:
    """One load level in a sweep — also the row shape of our ``sweep.csv``."""

    users: int
    rps: float
    p50: float
    p95: float
    p99: float
    error_pct: float
    db_tps: Optional[float] = None  # None when no --mysql-dsn was given
    p100: float = 0.0  # full-page-render proxy (slowest request, ms)


def _rows(csv_text: str) -> List[Dict[str, str]]:
    return list(csv.DictReader(io.StringIO(csv_text)))


def parse_locust_stats(csv_text: str) -> RunStats:
    """Read the "Aggregated" row from a ``*_stats.csv`` file's text.

    Raises ``ValueError`` if no aggregated row is present (a run that produced no
    stats — the caller treats that as a failed level, not a crash).
    """
    for row in _rows(csv_text):
        if (row.get("Name") or "").strip() == AGGREGATED:
            return RunStats(
                request_count=int(_to_float(row.get("Request Count"))),
                failure_count=int(_to_float(row.get("Failure Count"))),
                rps=_to_float(row.get("Requests/s")),
                p50=_to_float(row.get("50%")),
                p95=_to_float(row.get("95%")),
                p99=_to_float(row.get("99%")),
                p100=_to_float(row.get("100%")),
            )
    raise ValueError("no 'Aggregated' row found in stats CSV")


def parse_history(csv_text: str) -> List[Sample]:
    """Read the aggregated time series from a ``*_stats_history.csv`` file's text.

    Only the per-timestamp ``Aggregated`` rows are returned (Locust also writes a
    row per endpoint at each tick); samples with zero users (pre-spawn ticks) are
    dropped so a load axis never starts at 0.
    """
    samples: List[Sample] = []
    for row in _rows(csv_text):
        if (row.get("Name") or "").strip() != AGGREGATED:
            continue
        users = int(_to_float(row.get("User Count")))
        if users <= 0:
            continue
        samples.append(
            Sample(
                user_count=users,
                rps=_to_float(row.get("Requests/s")),
                p50=_to_float(row.get("50%")),
                p95=_to_float(row.get("95%")),
                p99=_to_float(row.get("99%")),
                failures_per_s=_to_float(row.get("Failures/s")),
                p100=_to_float(row.get("100%")),
            )
        )
    return samples


def aggregate_by_users(samples: List[Sample]) -> List[SweepPoint]:
    """Collapse a history series into one point per user level (mean of metrics).

    A single ramped run visits each user count many times; averaging the ticks at
    each level yields a clean monotonic curve to plot. ``error_pct`` is derived
    from the per-tick ``Failures/s`` / ``Requests/s``. ``p100`` (slowest request)
    is averaged too — it is the full-page-render proxy.
    """
    buckets: Dict[int, List[Sample]] = {}
    for sample in samples:
        buckets.setdefault(sample.user_count, []).append(sample)

    points: List[SweepPoint] = []
    for users in sorted(buckets):
        group = buckets[users]
        count = len(group)
        mean_rps = sum(s.rps for s in group) / count
        mean_failures_per_s = sum(s.failures_per_s for s in group) / count
        error_pct = (mean_failures_per_s / mean_rps * 100.0) if mean_rps > 0 else 0.0
        points.append(
            SweepPoint(
                users=users,
                rps=mean_rps,
                p50=sum(s.p50 for s in group) / count,
                p95=sum(s.p95 for s in group) / count,
                p99=sum(s.p99 for s in group) / count,
                error_pct=error_pct,
                db_tps=None,
                p100=sum(s.p100 for s in group) / count,
            )
        )
    return points


# ── sweep.csv round-trip (our own format) ─────────────────────────────────────

_SWEEP_COLUMNS = [f.name for f in fields(SweepPoint)]


def write_sweep_csv(points: List[SweepPoint], path: str) -> None:
    """Write sweep points to ``path`` — the reproducible measurement artifact.

    Plotting reads this back, so chart tweaks never require re-running the load.
    """
    with open(path, "w", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=_SWEEP_COLUMNS)
        writer.writeheader()
        for point in points:
            row = {name: getattr(point, name) for name in _SWEEP_COLUMNS}
            if point.db_tps is None:
                row["db_tps"] = ""
            writer.writerow(row)


def load_sweep(csv_text: str) -> List[SweepPoint]:
    """Read back a ``sweep.csv`` written by :func:`write_sweep_csv`."""
    points: List[SweepPoint] = []
    for row in _rows(csv_text):
        raw_db = (row.get("db_tps") or "").strip()
        points.append(
            SweepPoint(
                users=int(_to_float(row.get("users"))),
                rps=_to_float(row.get("rps")),
                p50=_to_float(row.get("p50")),
                p95=_to_float(row.get("p95")),
                p99=_to_float(row.get("p99")),
                error_pct=_to_float(row.get("error_pct")),
                db_tps=_to_float(raw_db) if raw_db else None,
                p100=_to_float(row.get("p100")),
            )
        )
    return points
