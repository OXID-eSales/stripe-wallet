"""Typed threshold evaluation for the heavy-load run.

A pure function of a stats snapshot -> ``(exit_code, breach_messages)``. No Locust
import, no I/O — unit-testable in isolation. The locustfile's ``quitting`` listener
and the workflow summary both consume this so triage is a glance at the typed
breach lines instead of a manual log dig.

Breach lines use one typed shape so the operator can group by category::

    BREACH category=<key> actual=<x> budget=<y>

Categories:
  - ``no_data``            — zero requests recorded (catastrophic); emitted
                             instead of a misleading ``error_pct=0%``.
  - ``error_pct``          — reliability breach.
  - ``p95_ms``             — latency at the user-visible percentile.
  - ``p99_ms``             — tail latency (contention / scheduler).
  - ``min_throughput_rps`` — throughput floor (serialised bottleneck).
"""
from __future__ import annotations

from typing import Dict, List, Tuple

# (operator, budget) per category. operator is "<=" (ceiling) or ">=" (floor).
# min_throughput_rps floor is lower than VBWD's API default (20) because OXID
# renders heavy server-side pages — a handful of rps is normal, not a bottleneck.
DEFAULT_THRESHOLDS: Dict[str, Tuple[str, float]] = {
    "error_pct": ("<=", 1.0),  # percent
    "p95_ms": ("<=", 1500.0),
    "p99_ms": ("<=", 3000.0),
    "min_throughput_rps": (">=", 5.0),
}


def evaluate(
    stats: Dict[str, float],
    thresholds: Dict[str, Tuple[str, float]] | None = None,
) -> Tuple[int, List[str]]:
    """Evaluate a stats snapshot against the budgets.

    Args:
        stats: keys ``num_requests``, ``num_failures``, ``p95_ms``, ``p99_ms``,
            ``throughput_rps``.
        thresholds: optional override of :data:`DEFAULT_THRESHOLDS`.

    Returns:
        ``(exit_code, breaches)`` — ``exit_code`` is 1 if any breach (including
        ``no_data``), else 0. ``breaches`` is one typed line per breached category.
    """
    thresholds = thresholds or DEFAULT_THRESHOLDS
    breaches: List[str] = []

    num_requests = stats.get("num_requests", 0) or 0
    if not num_requests:
        breaches.append("BREACH category=no_data actual=0_requests budget=>0")
        return 1, breaches

    num_failures = stats.get("num_failures", 0) or 0
    actuals: Dict[str, float] = {
        "error_pct": num_failures / num_requests * 100,
        "p95_ms": stats.get("p95_ms", 0.0) or 0.0,
        "p99_ms": stats.get("p99_ms", 0.0) or 0.0,
        "min_throughput_rps": stats.get("throughput_rps", 0.0) or 0.0,
    }

    for category, (operator, budget) in thresholds.items():
        if category not in actuals:
            continue
        actual = actuals[category]
        breached = actual > budget if operator == "<=" else actual < budget
        if breached:
            breaches.append(
                f"BREACH category={category} actual={actual:.2f} budget={budget}"
            )

    return (1 if breaches else 0), breaches
