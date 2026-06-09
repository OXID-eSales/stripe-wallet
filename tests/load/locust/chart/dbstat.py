"""Optional, read-only MySQL transaction-rate sampler.

Used by the sweep to add a "DB transactions / s" dimension. Purely a *reader* of
cumulative status counters — it never writes. ``PyMySQL`` is imported lazily so
the charting tool has no hard DB dependency: if the driver or the connection is
unavailable, sampling returns ``None`` and the DB chart is simply skipped.

DSN form: ``mysql://user:password@host:3306/dbname``.
"""
from __future__ import annotations

from typing import Dict, Optional
from urllib.parse import urlparse

# Com_commit + Com_rollback are MySQL's committed/rolled-back transaction
# counters (cumulative since server start). Their delta over the run window
# divided by seconds = transactions per second.
_COUNTERS = ("Com_commit", "Com_rollback")


def _parse_dsn(dsn: str) -> Optional[Dict[str, object]]:
    parsed = urlparse(dsn)
    if parsed.scheme not in ("mysql", "mysql+pymysql") or not parsed.hostname:
        return None
    return {
        "host": parsed.hostname,
        "port": parsed.port or 3306,
        "user": parsed.username or "root",
        "password": parsed.password or "",
        "database": (parsed.path or "/").lstrip("/") or None,
        "connect_timeout": 5,
    }


def sample_db_counters(dsn: str) -> Optional[Dict[str, float]]:
    """Return ``{"txns": <cumulative count>}`` or ``None`` if sampling is impossible.

    Never raises — a missing driver, an unreachable DB, a bad DSN, or a
    permissions error all degrade to ``None`` so a sweep keeps producing latency
    charts regardless.
    """
    params = _parse_dsn(dsn)
    if params is None:
        return None
    try:
        import pymysql  # noqa: PLC0415 — lazy: optional dependency
    except ImportError:
        return None
    try:
        connection = pymysql.connect(**params)  # type: ignore[arg-type]
        try:
            total = 0.0
            with connection.cursor() as cursor:
                for counter in _COUNTERS:
                    cursor.execute("SHOW GLOBAL STATUS LIKE %s", (counter,))
                    row = cursor.fetchone()
                    if row:
                        total += float(row[1])
        finally:
            connection.close()
        return {"txns": total}
    except Exception:  # noqa: BLE001 — any DB error -> skip the dimension, don't crash
        return None


def compute_db_tps(
    before: Optional[Dict[str, float]],
    after: Optional[Dict[str, float]],
    seconds: float,
) -> Optional[float]:
    """Transactions per second from two counter samples; ``None`` if not measurable.

    Pure arithmetic (unit-tested without a DB). A counter reset (after < before)
    or non-positive window yields ``None`` rather than a bogus negative rate.
    """
    if not before or not after or seconds <= 0:
        return None
    delta = after["txns"] - before["txns"]
    if delta < 0:
        return None
    return delta / seconds
