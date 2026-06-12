"""Capacity-sweep charting for the OXID Stripe heavy-load harness.

Mirrors the VBWD-platform charting tool. Thin layers, each independently
unit-testable:

- :mod:`parse`  — pure Locust-CSV / sweep-CSV readers (string -> dataclasses).
- :mod:`plot`   — pure scale transforms + a single matplotlib ``savefig`` edge.
- :mod:`charts` — the default chart set (which fields, which scales).
- :mod:`render` — charts from ONE existing run's ``stats_history.csv`` (no load).
- :mod:`embed`  — base64-inline the PNG charts into an ``index.html``.
- :mod:`sweep`  — orchestrates N headless Locust runs into ``sweep.csv`` + charts.
- :mod:`dbstat` — optional read-only MySQL transaction-rate sampler.
- :mod:`k6`        — pure k6-NDJSON/summary parsers -> the same Sample/SweepPoint.
- :mod:`k6_render` — the k6 counterpart of :mod:`render`: charts + HTML + summary.
"""
