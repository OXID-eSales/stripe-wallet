"""Chart rendering — pure scale helpers + a single matplotlib ``savefig`` edge.

Uses the headless Agg backend (no display), so it runs in CI and under pytest.
Each axis is independently selectable ``linear`` / ``log10`` / ``ln`` — latency
spans a wide ms range that is only legible on a log axis.
"""
from __future__ import annotations

import math
from dataclasses import dataclass
from typing import List, Optional, Sequence

import matplotlib

matplotlib.use("Agg")  # headless — must precede pyplot import
import matplotlib.pyplot as plt  # noqa: E402  (backend set above)

# scale name -> log base (None = linear). Single source of truth for which scales
# exist; both apply_scale and the matplotlib mapping read it.
LOG_BASES = {"linear": None, "log10": 10.0, "ln": math.e}
SCALES = tuple(LOG_BASES)


@dataclass
class Series:
    """One plotted line: which SweepPoint attribute, and its legend label."""

    field: str
    label: str


def apply_scale(values: Sequence[float], scale: str) -> List[float]:
    """Pure transform of a value list under ``scale``.

    ``linear`` returns the values unchanged; ``log10`` / ``ln`` return the log,
    mapping non-positive inputs to ``nan`` (log of 0/negative is undefined, and
    matplotlib skips ``nan`` points). Unknown scale -> ValueError.
    """
    if scale not in LOG_BASES:
        raise ValueError(f"unknown scale {scale!r}; expected one of {SCALES}")
    base = LOG_BASES[scale]
    if base is None:
        return [float(value) for value in values]
    return [math.log(value, base) if value > 0 else math.nan for value in values]


def _set_axis_scale(set_scale, scale: str) -> None:
    base = LOG_BASES[scale]
    if base is None:
        set_scale("linear")
    else:
        set_scale("log", base=base)


def _series_xy(points, x_field: str, y_field: str, x_is_log: bool, y_is_log: bool):
    """Extract (xs, ys) for one series, dropping points unusable on a log axis.

    A point is skipped when either coordinate is None (e.g. db_tps without a DSN)
    or is <=0 on an axis that will be log-scaled.
    """
    xs: List[float] = []
    ys: List[float] = []
    for point in points:
        x_value = getattr(point, x_field)
        y_value = getattr(point, y_field)
        if x_value is None or y_value is None:
            continue
        if (x_is_log and x_value <= 0) or (y_is_log and y_value <= 0):
            continue
        xs.append(float(x_value))
        ys.append(float(y_value))
    return xs, ys


def render_chart(
    points,
    *,
    x_field: str,
    x_label: str,
    x_scale: str,
    y_series: List[Series],
    y_label: str,
    y_scale: str,
    out_path: str,
    title: Optional[str] = None,
    connect: bool = True,
) -> str:
    """Render one XY chart (one line per series) to ``out_path``; return the path.

    ``connect=True`` draws markers joined by a line (sorted by x so it reads
    left-to-right — correct when x is the monotonic controlled variable);
    ``connect=False`` draws markers only (a scatter).
    """
    for scale in (x_scale, y_scale):
        if scale not in LOG_BASES:
            raise ValueError(f"unknown scale {scale!r}; expected one of {SCALES}")
    x_is_log = LOG_BASES[x_scale] is not None
    y_is_log = LOG_BASES[y_scale] is not None

    figure, axes = plt.subplots(figsize=(8, 5))
    style = "-o" if connect else "o"
    for series in y_series:
        xs, ys = _series_xy(points, x_field, series.field, x_is_log, y_is_log)
        if connect and xs:
            xs, ys = map(list, zip(*sorted(zip(xs, ys))))
        axes.plot(xs, ys, style, label=series.label, markersize=4)

    _set_axis_scale(axes.set_xscale, x_scale)
    _set_axis_scale(axes.set_yscale, y_scale)
    axes.set_xlabel(x_label)
    axes.set_ylabel(y_label)
    if title:
        axes.set_title(title)
    axes.grid(True, which="both", linestyle=":", alpha=0.5)
    if len(y_series) > 1:
        axes.legend()
    figure.tight_layout()
    figure.savefig(out_path, dpi=120)
    plt.close(figure)
    return out_path
