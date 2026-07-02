"""Put the locust dir (parent of this tests/ dir) on sys.path so the unit tests
import ``thresholds`` and the ``chart`` package exactly as the runtime does
(Locust adds the locustfile dir; the chart tool runs from the locust dir)."""
import os
import sys

LOCUST_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if LOCUST_DIR not in sys.path:
    sys.path.insert(0, LOCUST_DIR)
