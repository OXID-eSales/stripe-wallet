"""Inline the capacity-chart PNGs into the Locust ``index.html`` (base64).

Makes the single report file self-contained — open it anywhere, charts included,
no sibling PNGs needed. Idempotent: a previously embedded block (marked by
``data-charts-embed``) is replaced, never stacked, so re-running never duplicates.

    python -m chart.embed --index load-report/index.html --charts load-report/charts/*.png

Best-effort by design — the workflow runs it with ``continue-on-error`` so a
charting hiccup never fails a completed load run.
"""
from __future__ import annotations

import argparse
import base64
import os
import re
import sys
from typing import List, Optional

_MARKER = "data-charts-embed"
# Matches a previously inserted <section data-charts-embed> … </section> block.
_EXISTING = re.compile(
    rf'<section[^>]*{_MARKER}.*?</section>', re.DOTALL | re.IGNORECASE
)
# The document's real </body> — the one that closes the page (followed by
# </html>), as opposed to any </body> embedded in a script string or JSON blob.
_CLOSING_BODY = re.compile(r'</body\s*>\s*</html\s*>', re.IGNORECASE)


def _img_tag(png_path: str) -> str:
    with open(png_path, "rb") as handle:
        encoded = base64.b64encode(handle.read()).decode("ascii")
    name = os.path.basename(png_path)
    return (
        f'<figure style="margin:1rem 0">'
        f'<figcaption style="font:600 14px sans-serif;margin-bottom:.25rem">{name}</figcaption>'
        f'<img alt="{name}" style="max-width:100%;border:1px solid #ddd" '
        f'src="data:image/png;base64,{encoded}"></figure>'
    )


def _param_table(param_rows: List[tuple]) -> str:
    """Render the user-set run parameters as a small table (empty -> "")."""
    if not param_rows:
        return ""
    cell = "border:1px solid #ddd;padding:.3rem .6rem"
    body = "".join(
        f'<tr><td style="{cell}">{label}</td><td style="{cell}">{value}</td></tr>'
        for label, value in param_rows
    )
    return (
        '<h2 style="font:700 18px sans-serif">Run parameters</h2>'
        '<table style="border-collapse:collapse;font:14px sans-serif;margin-bottom:1rem">'
        f"<tbody>{body}</tbody></table>"
    )


def build_section(png_paths: List[str], param_rows: Optional[List[tuple]] = None) -> str:
    figures = "\n".join(_img_tag(path) for path in png_paths if os.path.exists(path))
    charts_heading = (
        '<h2 style="font:700 18px sans-serif">Capacity charts</h2>' if figures else ""
    )
    return (
        f'<section {_MARKER} style="padding:1rem 2rem">'
        f"{_param_table(param_rows or [])}"
        f"{charts_heading}{figures}</section>"
    )


def embed(
    index_html: str,
    png_paths: List[str],
    param_rows: Optional[List[tuple]] = None,
) -> str:
    """Return ``index_html`` with the charts/parameters section before ``</body>``.

    Pure string transform (unit-testable). If a previous embed block exists it is
    replaced; if there is no ``</body>`` the section is appended. ``param_rows`` is
    an optional list of ``(label, value)`` shown as a "Run parameters" table above
    the charts — the user-set run inputs the Locust report header omits.
    """
    section = build_section(png_paths, param_rows)
    if _EXISTING.search(index_html):
        return _EXISTING.sub(section, index_html, count=1)
    # Insert before the document-closing </body> — the one immediately followed
    # by </html>. The Locust report bundles a chart-popup template string
    # ('<body …></body>') inside its head module script, and stray "</body>"
    # text can also appear inside the templateArgs JSON; injecting at either
    # corrupts the page and blanks it. Anchoring on the </body></html> pair
    # targets the real body unambiguously.
    closing = _CLOSING_BODY.search(index_html)
    if closing:
        at = closing.start()
        return index_html[:at] + section + "\n" + index_html[at:]
    # Fallbacks for malformed input: last </body>, else append.
    index = index_html.rfind("</body>")
    if index != -1:
        return index_html[:index] + section + "\n" + index_html[index:]
    return index_html + section


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(
        prog="python -m chart.embed",
        description="Inline chart PNGs into the Locust index.html.",
    )
    parser.add_argument("--index", required=True, help="path to Locust index.html")
    parser.add_argument("--charts", nargs="*", default=[], help="chart PNG paths")
    parser.add_argument(
        "--param",
        action="append",
        default=[],
        metavar="LABEL=VALUE",
        help="user-set run parameter row for the report (repeatable)",
    )
    args = parser.parse_args(argv)

    if not os.path.exists(args.index):
        print(f"index not found: {args.index}", file=sys.stderr)
        return 1

    pngs = [path for path in args.charts if os.path.exists(path)]
    param_rows = []
    for item in args.param:
        label, _, value = item.partition("=")
        param_rows.append((label.strip(), value.strip()))

    if not pngs and not param_rows:
        print("no charts or parameters to embed — leaving index.html unchanged", file=sys.stderr)
        return 0

    with open(args.index, encoding="utf-8") as handle:
        original = handle.read()
    with open(args.index, "w", encoding="utf-8") as handle:
        handle.write(embed(original, pngs, param_rows))
    print(f"embedded {len(pngs)} chart(s) + {len(param_rows)} parameter(s) into {args.index}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
