"""Heavy-load profile for the OXID Stripe shop (HTTP-level).

Triggered manually from the module's GitHub Actions UI (`Load Test (Locust)`
workflow); can also be run locally against any reachable shop:

    locust -f locustfile.py --host https://pay1.oxid.dev

Mirrors the VBWD-platform `heavy-load` harness (pre-mint/pool, load-shed
tolerance, typed threshold enforcement via tests/load/locust/thresholds.py), but
the scenarios drive OXID frontend URLs (`index.php?cl=…`) instead of a JSON API.

SCOPE: Locust is HTTP-level. It exercises page rendering, session + basket, and
the Stripe `createCheckoutSession` endpoint. It does NOT drive the Stripe hosted
Checkout redirect or the 3DS browser flow — use the k6 browser-mode `load-test`
for that. A request that hits a business precondition we cannot satisfy over raw
HTTP (login wall, AGB, address) is recorded as a tolerated "shed", not a failure,
so the error rate reflects real server faults, not harness limits.
"""
from __future__ import annotations

import os
import random
import re
from typing import Any, List, Optional

import urllib3
from locust import HttpUser, between, events, task

# Sibling import: Locust puts the locustfile's dir on sys.path[0] at load time,
# so `thresholds` resolves at runtime (the unit-test conftest adds the same dir).
from thresholds import DEFAULT_THRESHOLDS, evaluate

# The in-CI shop serves a self-signed cert on https://oxideshop.local. Set
# LOAD_INSECURE_TLS=1 to skip verification (and silence the warning) for that.
_INSECURE_TLS = os.environ.get("LOAD_INSECURE_TLS") == "1"
if _INSECURE_TLS:
    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# ── Config (env-overridable) ──────────────────────────────────────────────────
USER_PREFIX = os.environ.get("LOAD_USER_PREFIX", "loadtest_user_")
USER_DOMAIN = os.environ.get("LOAD_USER_DOMAIN", "@oxid-esales.dev")
USER_COUNT = int(os.environ.get("LOAD_USER_COUNT", "200"))
TEST_PASSWORD = os.environ.get("LOAD_TEST_PASSWORD", "useruser")
SEARCH_TERM = os.environ.get("LOAD_SEARCH_TERM", "kite")
# Optional explicit ids; if unset we scrape them from the start/search HTML.
PRODUCT_ANID = os.environ.get("LOAD_PRODUCT_ANID", "")
CATEGORY_CNID = os.environ.get("LOAD_CATEGORY_CNID", "")

# Treat OXID's frequent redirects and graceful load-shedding as NOT failures.
# 200 = ok; 301/302 = OXID redirect (basket/checkout steps redirect constantly);
# 429/503 = rate-limit / pool-timeout back-off (the system protecting itself,
# and a CI measurement artifact since all load comes from one IP). Genuine
# faults (other 5xx, drops, timeouts) still fail.
_OK = {200, 301, 302, 303, 429, 503}

_STOKEN_RE = re.compile(r'name="stoken"\s+value="([^"]+)"')
_ANID_RE = re.compile(r'[?&]anid=([0-9a-zA-Z._-]+)')
_CNID_RE = re.compile(r'[?&]cnid=([0-9a-zA-Z._-]+)')


def _emails() -> List[str]:
    return [f"{USER_PREFIX}{i:03d}{USER_DOMAIN}" for i in range(1, USER_COUNT + 1)]


def _scrape(pattern: re.Pattern, html: str, limit: int = 10) -> List[str]:
    seen: List[str] = []
    for match in pattern.findall(html or ""):
        if match not in seen:
            seen.append(match)
        if len(seen) >= limit:
            break
    return seen


class _Catalog:
    """Process-wide ids discovered once at test_start (or from env overrides)."""

    anids: List[str] = []
    cnids: List[str] = []

    @classmethod
    def discover(cls, client) -> None:
        if cls.anids or cls.cnids:
            return
        if PRODUCT_ANID:
            cls.anids = [PRODUCT_ANID]
        if CATEGORY_CNID:
            cls.cnids = [CATEGORY_CNID]
        try:
            html = client.get("/index.php?cl=start", name="GET start (warm)").text
            html += client.get(
                f"/index.php?cl=search&searchparam={SEARCH_TERM}",
                name="GET search (warm)",
            ).text
        except Exception:  # noqa: BLE001 — discovery is best-effort
            return
        if not cls.anids:
            cls.anids = _scrape(_ANID_RE, html)
        if not cls.cnids:
            cls.cnids = _scrape(_CNID_RE, html)


@events.test_start.add_listener
def _warm(environment, **_: Any) -> None:
    """Discover product/category ids once, off the measured hot path, with a
    plain client. Empty results just mean the id-dependent tasks self-skip."""
    host = (environment.host or "").rstrip("/")
    if not host:
        return
    import requests  # local import: only needed here, off the VU path

    class _Shim:
        def get(self, path, name=None):  # noqa: ARG002 — name kept for parity
            return requests.get(f"{host}{path}", timeout=15, verify=not _INSECURE_TLS)

    _Catalog.discover(_Shim())
    print(
        f"catalog warm: {len(_Catalog.anids)} product anid(s), "
        f"{len(_Catalog.cnids)} category cnid(s) discovered"
    )


def _read(client, url: str, name: str) -> Optional[str]:
    """GET with load-shed tolerance; returns response text on a real 200, else None."""
    with client.get(url, name=name, catch_response=True) as resp:
        if resp.status_code in _OK:
            resp.success()
            return resp.text if resp.status_code == 200 else None
        resp.failure(f"{name} -> {resp.status_code}")
        return None


def _stoken(html: Optional[str]) -> str:
    match = _STOKEN_RE.search(html or "")
    return match.group(1) if match else ""


# ── Scenarios ─────────────────────────────────────────────────────────────────

class AnonymousBrowse(HttpUser):
    """Public catalog reads — heaviest weight, simulates window-shoppers."""

    weight = 6
    wait_time = between(0.5, 2.0)

    def on_start(self) -> None:
        if _INSECURE_TLS:
            self.client.verify = False

    @task(4)
    def start_page(self) -> None:
        # NB: must NOT be named `start` — that shadows HttpUser.start(group),
        # the lifecycle method Locust's runner calls to launch the VU greenlet.
        _read(self.client, "/index.php?cl=start", "GET start")

    @task(3)
    def search(self) -> None:
        _read(
            self.client,
            f"/index.php?cl=search&searchparam={SEARCH_TERM}",
            "GET search",
        )

    @task(3)
    def details(self) -> None:
        if not _Catalog.anids:
            return
        anid = random.choice(_Catalog.anids)
        _read(self.client, f"/index.php?cl=details&anid={anid}", "GET details")

    @task(2)
    def category(self) -> None:
        if not _Catalog.cnids:
            return
        cnid = random.choice(_Catalog.cnids)
        _read(self.client, f"/index.php?cl=alist&cnid={cnid}", "GET alist")


class CheckoutFlow(HttpUser):
    """Authenticated session + basket + checkout-page renders, ending in the
    Stripe `createCheckoutSession` call — the module-specific hot path.

    Each VU logs in as a seeded user, fills a basket, walks the checkout pages,
    and posts createCheckoutSession. Steps that hit a precondition we cannot
    satisfy over HTTP are tolerated (see `_OK`) so the error rate stays honest.
    """

    weight = 1
    wait_time = between(1.0, 3.0)

    def on_start(self) -> None:
        if _INSECURE_TLS:
            self.client.verify = False
        _Catalog.discover(self.client)
        self._login()

    def _login(self) -> None:
        # Grab a fresh stoken from the start page, then POST the login form.
        html = _read(self.client, "/index.php?cl=start", "GET start (login)")
        email = random.choice(_emails())
        with self.client.post(
            "/index.php",
            data={
                "cl": "account",
                "fnc": "login_noredirect",
                "lgn_usr": email,
                "lgn_pwd": TEST_PASSWORD,
                "stoken": _stoken(html),
            },
            name="POST login",
            catch_response=True,
        ) as resp:
            if resp.status_code in _OK:
                resp.success()
            else:
                resp.failure(f"login -> {resp.status_code}")

    @task
    def checkout(self) -> None:
        # 1. Add a product to the basket (needs an anid + stoken).
        html = _read(self.client, "/index.php?cl=start", "GET start (basket)")
        if _Catalog.anids:
            aid = random.choice(_Catalog.anids)
            with self.client.post(
                "/index.php",
                data={
                    "cl": "basket",
                    "fnc": "tobasket",
                    "aid": aid,
                    "am": "1",
                    "stoken": _stoken(html),
                },
                name="POST tobasket",
                catch_response=True,
            ) as resp:
                resp.success() if resp.status_code in _OK else resp.failure(
                    f"tobasket -> {resp.status_code}"
                )

        # 2. Walk the heavy checkout-page renders.
        order_html = None
        for cl, name in (
            ("basket", "GET basket"),
            ("user", "GET user"),
            ("payment", "GET payment"),
            ("order", "GET order"),
        ):
            text = _read(self.client, f"/index.php?cl={cl}", name)
            if cl == "order":
                order_html = text

        # 3. The Stripe hot path: create a Checkout Session. Mirrors the JS
        #    (order_submit_controller): POST JSON with stoken + ord_agb in the
        #    query string. A 200 with a session is success; a precondition
        #    bounce (login/AGB/address) is tolerated; only a 5xx is a failure.
        stoken = _stoken(order_html)
        url = (
            "/index.php?cl=StripeOrderController&fnc=createCheckoutSession"
            f"&stoken={stoken}&ord_agb=1"
        )
        with self.client.post(
            url,
            json={"capture": "automatic"},
            headers={"X-Requested-With": "XMLHttpRequest"},
            name="POST createCheckoutSession",
            catch_response=True,
        ) as resp:
            if resp.status_code in _OK or 400 <= resp.status_code < 500:
                # 4xx here = a precondition we can't meet over raw HTTP, not a
                # server fault. Counting it would drown the real error signal.
                resp.success()
            else:
                resp.failure(
                    f"createCheckoutSession -> {resp.status_code} {resp.text[:160]}"
                )

        # 4. Exercise the OXID order controller's place-order action directly
        #    (cl=order&fnc=execute). This is the classic non-JS submit path and
        #    the heaviest server-side write on the checkout: it builds the basket
        #    order, runs validation, and hands off to the Stripe smart-contract.
        #    Over raw HTTP a precondition bounce (AGB/address/payment) is the
        #    normal outcome, so 2xx/3xx/4xx are all tolerated — only a 5xx faults.
        with self.client.post(
            "/index.php",
            data={
                "cl": "order",
                "fnc": "execute",
                "stoken": stoken,
                "ord_agb": "1",
                "sDeliveryAddressMD5": "",
            },
            name="POST order execute",
            catch_response=True,
        ) as resp:
            if resp.status_code in _OK or 400 <= resp.status_code < 500:
                resp.success()
            else:
                resp.failure(
                    f"order execute -> {resp.status_code} {resp.text[:160]}"
                )


# ── Threshold enforcement (typed, via thresholds.py) ──────────────────────────

@events.quitting.add_listener
def _enforce_thresholds(environment, **_: Any) -> None:
    """Set ``environment.process_exit_code`` from the typed evaluator so a budget
    breach prints one ``BREACH category=…`` line and fails the run (and the job).
    """
    stats = environment.stats.total
    snapshot = {
        "num_requests": stats.num_requests,
        "num_failures": stats.num_failures,
        "p95_ms": stats.get_response_time_percentile(0.95) or 0.0,
        "p99_ms": stats.get_response_time_percentile(0.99) or 0.0,
        "throughput_rps": stats.total_rps,
    }
    thresholds = dict(DEFAULT_THRESHOLDS)
    # Throughput is an *outcome* of the chosen VU count + think-time, not an SLO:
    # a fixed floor false-fails low-VU runs. The job gates on latency + errors.
    thresholds.pop("min_throughput_rps", None)
    thresholds["error_pct"] = ("<=", float(os.environ.get("LOAD_FAIL_PCT_ERROR", "1.0")))
    thresholds["p95_ms"] = ("<=", float(os.environ.get("LOAD_FAIL_P95_MS", "1500")))

    exit_code, breaches = evaluate(snapshot, thresholds)
    for breach in breaches:
        print(breach)
    environment.process_exit_code = exit_code
