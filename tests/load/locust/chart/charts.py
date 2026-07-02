"""The default chart set — shared by the sweep runner and the from-history mode.

Keeps the "which charts, which fields, which default scales" decision in one
place (DRY) so both entry points emit the same, recognisable set.
"""
from __future__ import annotations

import os
from typing import List

from . import plot
from .parse import SweepPoint

_LATENCY_SERIES = [
    plot.Series("p50", "p50"),
    plot.Series("p95", "p95"),
    plot.Series("p99", "p99"),
]


def latency_charts(
    points: List[SweepPoint],
    out_dir: str,
    *,
    x_scale: str = "linear",
    y_scale: str = "log10",
    connect: bool = True,
) -> List[str]:
    """Throughput->load and concurrent-users->latency (the two universal charts).

    Both are keyed on **concurrent users** — the monotonic *controlled* variable.
    We deliberately do NOT plot latency against throughput as a trend: throughput
    is an emergent, *saturating* result, so past the knee it pins and oscillates
    (Little's Law inversely couples it with latency under congestion).
    ``throughput-users.png`` shows throughput rising with load and flattening at
    the ceiling ("max sustainable rps"); ``users-latency.png`` shows the latency
    trend; ``throughput-latency.png`` is the raw scatter, kept as a standard view.
    """
    os.makedirs(out_dir, exist_ok=True)
    written: List[str] = []

    written.append(
        plot.render_chart(
            points,
            x_field="users",
            x_label="concurrent users",
            x_scale=x_scale,
            y_series=[plot.Series("rps", "throughput")],
            y_label="throughput (requests / s)",
            y_scale="linear",  # rps is a small linear range; log would distort the plateau
            out_path=os.path.join(out_dir, "throughput-users.png"),
            title="Throughput vs concurrent users (saturation plateau = ceiling)",
            connect=connect,
        )
    )
    written.append(
        plot.render_chart(
            points,
            x_field="users",
            x_label="concurrent users",
            x_scale=x_scale,
            y_series=_LATENCY_SERIES,
            y_label="response time (ms)",
            y_scale=y_scale,
            out_path=os.path.join(out_dir, "users-latency.png"),
            title="Concurrent users vs response time",
            connect=connect,
        )
    )
    written.append(
        plot.render_chart(
            points,
            x_field="rps",
            x_label="throughput (requests / s)",
            x_scale=x_scale,
            y_series=_LATENCY_SERIES,
            y_label="response time (ms)",
            y_scale=y_scale,
            out_path=os.path.join(out_dir, "throughput-latency.png"),
            title="Throughput vs response time",
            connect=False,
        )
    )
    return written


def load_charts(
    points: List[SweepPoint],
    out_dir: str,
    *,
    x_scale: str = "linear",
    y_scale: str = "log10",
    connect: bool = True,
) -> List[str]:
    """Two load-keyed charts (X = concurrent users, different Y).

    ``errors-users.png`` — error rate vs load (Y forced linear: a 0-100% rate has
    a meaningful floor a log axis would hide).
    ``render-users.png`` — full-page render (the slowest request, p100) vs load.
    """
    os.makedirs(out_dir, exist_ok=True)
    written: List[str] = []

    written.append(
        plot.render_chart(
            points,
            x_field="users",
            x_label="concurrent users",
            x_scale=x_scale,
            y_series=[plot.Series("error_pct", "error %")],
            y_label="failed requests (%)",
            y_scale="linear",
            out_path=os.path.join(out_dir, "errors-users.png"),
            title="Error rate vs concurrent users",
            connect=connect,
        )
    )
    written.append(
        plot.render_chart(
            points,
            x_field="users",
            x_label="concurrent users",
            x_scale=x_scale,
            y_series=[plot.Series("p100", "full-page render")],
            y_label="full-page render — slowest request (ms)",
            y_scale=y_scale,
            out_path=os.path.join(out_dir, "render-users.png"),
            title="Full-page render vs concurrent users",
            connect=connect,
        )
    )
    return written


def full_chart_set(
    points: List[SweepPoint],
    out_dir: str,
    *,
    x_scale: str = "linear",
    y_scale: str = "log10",
) -> List[str]:
    """The complete sweep deliverable: latency curves + errors/render + DB pressure.

    The DB-pressure chart is emitted only when at least one point carries a
    db_tps sample (i.e. the sweep ran with ``--mysql-dsn``); it is skipped, not
    faked, otherwise.
    """
    written = latency_charts(points, out_dir, x_scale=x_scale, y_scale=y_scale, connect=True)
    written += load_charts(points, out_dir, x_scale=x_scale, y_scale=y_scale, connect=True)

    if any(point.db_tps is not None for point in points):
        written.append(
            plot.render_chart(
                points,
                x_field="db_tps",
                x_label="DB transactions / s",
                x_scale=x_scale,
                y_series=[plot.Series("p95", "p95")],
                y_label="response time (ms)",
                y_scale=y_scale,
                out_path=os.path.join(out_dir, "db-latency.png"),
                title="DB pressure vs response time",
                connect=True,
            )
        )

    return written
