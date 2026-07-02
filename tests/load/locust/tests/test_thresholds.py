"""Unit tests for the pure threshold evaluator."""
from thresholds import DEFAULT_THRESHOLDS, evaluate


def _clean_snapshot(**overrides):
    snapshot = {
        "num_requests": 1000,
        "num_failures": 0,
        "p95_ms": 100.0,
        "p99_ms": 200.0,
        "throughput_rps": 50.0,
    }
    snapshot.update(overrides)
    return snapshot


def test_all_within_budget_passes():
    exit_code, breaches = evaluate(_clean_snapshot())
    assert exit_code == 0
    assert breaches == []


def test_zero_requests_is_no_data_not_zero_error():
    exit_code, breaches = evaluate(_clean_snapshot(num_requests=0))
    assert exit_code == 1
    assert any("category=no_data" in line for line in breaches)
    # must NOT misreport a clean 0% error rate
    assert not any("category=error_pct" in line for line in breaches)


def test_error_pct_breach():
    exit_code, breaches = evaluate(_clean_snapshot(num_requests=100, num_failures=5))
    assert exit_code == 1
    assert any("category=error_pct actual=5.00" in line for line in breaches)


def test_p95_breach_against_override():
    thresholds = dict(DEFAULT_THRESHOLDS)
    thresholds["p95_ms"] = ("<=", 50.0)
    exit_code, breaches = evaluate(_clean_snapshot(p95_ms=120.0), thresholds)
    assert exit_code == 1
    assert any("category=p95_ms actual=120.00 budget=50.0" in line for line in breaches)


def test_throughput_floor_breach():
    exit_code, breaches = evaluate(_clean_snapshot(throughput_rps=1.0))
    assert exit_code == 1
    assert any("category=min_throughput_rps" in line for line in breaches)


def test_throughput_floor_satisfied_does_not_breach():
    _, breaches = evaluate(_clean_snapshot(throughput_rps=10.0))
    assert not any("category=min_throughput_rps" in line for line in breaches)
