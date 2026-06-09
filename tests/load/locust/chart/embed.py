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


def build_section(png_paths: List[str]) -> str:
    figures = "\n".join(_img_tag(path) for path in png_paths if os.path.exists(path))
    return (
        f'<section {_MARKER} style="padding:1rem 2rem">'
        f'<h2 style="font:700 18px sans-serif">Capacity charts</h2>'
        f"{figures}</section>"
    )


def embed(index_html: str, png_paths: List[str]) -> str:
    """Return ``index_html`` with the charts section inserted before ``</body>``.

    Pure string transform (unit-testable). If a previous embed block exists it is
    replaced; if there is no ``</body>`` the section is appended.
    """
    section = build_section(png_paths)
    if _EXISTING.search(index_html):
        return _EXISTING.sub(section, index_html, count=1)
    if "</body>" in index_html:
        return index_html.replace("</body>", section + "\n</body>", 1)
    return index_html + section


def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(
        prog="python -m chart.embed",
        description="Inline chart PNGs into the Locust index.html.",
    )
    parser.add_argument("--index", required=True, help="path to Locust index.html")
    parser.add_argument("--charts", nargs="+", required=True, help="chart PNG paths")
    args = parser.parse_args(argv)

    if not os.path.exists(args.index):
        print(f"index not found: {args.index}", file=sys.stderr)
        return 1

    pngs = [path for path in args.charts if os.path.exists(path)]
    if not pngs:
        print("no chart PNGs to embed — leaving index.html unchanged", file=sys.stderr)
        return 0

    with open(args.index, encoding="utf-8") as handle:
        original = handle.read()
    with open(args.index, "w", encoding="utf-8") as handle:
        handle.write(embed(original, pngs))
    print(f"embedded {len(pngs)} chart(s) into {args.index}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
