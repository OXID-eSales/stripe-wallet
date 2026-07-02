"""Unit tests for the pure Locust-CSV parsers + sweep-CSV round-trip + embed."""
from chart.embed import embed
from chart.parse import (
    aggregate_by_users,
    load_sweep,
    parse_history,
    parse_locust_stats,
    write_sweep_csv,
)

_STATS_CSV = (
    "Type,Name,Request Count,Failure Count,Median Response Time,Average Response Time,"
    "Min Response Time,Max Response Time,Average Content Size,Requests/s,Failures/s,"
    "50%,66%,75%,80%,90%,95%,98%,99%,99.9%,99.99%,100%\n"
    "GET,GET start,900,0,40,50,10,300,1200,30.0,0.0,40,50,60,70,90,110,180,220,290,300,300\n"
    "GET,Aggregated,1000,10,45,55,10,800,1300,33.3,0.5,45,55,65,75,95,120,200,250,700,800,800\n"
)

_HISTORY_CSV = (
    "Timestamp,User Count,Type,Name,Requests/s,Failures/s,50%,95%,99%,100%\n"
    "1,0,,Aggregated,0,0,0,0,0,0\n"           # pre-spawn tick — dropped
    "2,10,,Aggregated,20,0,40,100,150,300\n"
    "3,10,,Aggregated,22,1,42,110,160,320\n"
    "4,20,,Aggregated,30,0,60,200,260,500\n"
)


def test_parse_locust_stats_reads_aggregated_row():
    stats = parse_locust_stats(_STATS_CSV)
    assert stats.request_count == 1000
    assert stats.failure_count == 10
    assert stats.rps == 33.3
    assert stats.p95 == 120.0
    assert stats.p100 == 800.0
    assert round(stats.error_pct, 1) == 1.0


def test_parse_history_drops_zero_user_ticks():
    samples = parse_history(_HISTORY_CSV)
    assert [s.user_count for s in samples] == [10, 10, 20]


def test_aggregate_by_users_means_and_error_rate():
    points = aggregate_by_users(parse_history(_HISTORY_CSV))
    assert [p.users for p in points] == [10, 20]
    ten = points[0]
    assert ten.rps == 21.0  # mean of 20 and 22
    # error_pct = mean(failures/s) / mean(rps) * 100 = 0.5 / 21 * 100
    assert round(ten.error_pct, 3) == round(0.5 / 21.0 * 100, 3)


def test_sweep_csv_round_trip(tmp_path):
    points = aggregate_by_users(parse_history(_HISTORY_CSV))
    path = tmp_path / "sweep.csv"
    write_sweep_csv(points, str(path))
    restored = load_sweep(path.read_text())
    assert [p.users for p in restored] == [p.users for p in points]
    assert restored[0].db_tps is None  # empty cell -> None, not 0.0


def test_embed_is_idempotent():
    html = "<html><body><h1>report</h1></body></html>"
    # No real PNG needed: build_section skips missing files, so embed just
    # inserts an (empty) marked section — the idempotency contract is what we test.
    once = embed(html, [])
    twice = embed(once, [])
    assert once.count("data-charts-embed") == 1
    assert twice.count("data-charts-embed") == 1  # replaced, not stacked
    assert twice.count("</body>") == 1


def test_embed_renders_run_parameters_table():
    html = "<html><body><h1>report</h1></body></html>"
    out = embed(html, [], [("Scenario", "all"), ("Concurrent users", "50")])
    assert "Run parameters" in out
    assert "Scenario" in out and "all" in out
    assert "Concurrent users" in out and "50" in out
    # Params live inside the single marked, idempotent section.
    assert out.count("data-charts-embed") == 1
    twice = embed(out, [], [("Scenario", "browse")])
    assert twice.count("data-charts-embed") == 1   # replaced, not stacked
    assert "browse" in twice and "all" not in twice


def test_embed_targets_real_body_not_head_script_string():
    # Regression: the Locust report bundles a chart-popup template string
    # ('<body …></body>') inside its head module script (ECharts save-as-image).
    # A naive find("</body>") injects there, corrupting the JS bundle and
    # blanking the page. Embedding must land in the REAL document body and leave
    # the script string byte-for-byte intact.
    script = "var b='<body style=\"margin:0;\"><img/></body>',w=open();"
    html = (
        "<html><head><script>" + script + "</script></head>"
        "<body><div id=\"root\"></div></body></html>"
    )
    out = embed(html, [])
    assert script in out                                  # JS string untouched
    assert out.endswith("</body></html>")                 # real closing tag kept
    assert out.count("data-charts-embed") == 1
    # section landed after the real mount point, not inside the head script
    assert out.index("data-charts-embed") > out.index('<div id="root"')
