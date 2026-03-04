#!/usr/bin/env python3
from __future__ import annotations

import argparse
import html
import json
import os
import zipfile
from typing import List, Tuple


def extract(zip_path: str) -> Tuple[dict, List[Tuple[str, str]]]:
    if not os.path.isfile(zip_path):
        raise FileNotFoundError(f"zip not found: {zip_path}")

    summary = {}
    patches: List[Tuple[str, str]] = []

    with zipfile.ZipFile(zip_path, "r") as zf:
        for name in sorted(zf.namelist()):
            data = zf.read(name).decode("utf-8", errors="replace")
            if name.endswith("golden-summary.json") or name.endswith("smoke-summary.json"):
                try:
                    summary = json.loads(data)
                except json.JSONDecodeError:
                    summary = {"parse_error": "Unable to parse summary JSON"}
            if name.endswith(".patch") or name.endswith(".diff"):
                patches.append((name, data))

    return summary, patches


def to_html(summary: dict, patches: List[Tuple[str, str]]) -> str:
    result = html.escape(str(summary.get("result", "unknown")))
    mismatches = summary.get("mismatches", []) if isinstance(summary, dict) else []

    rows = []
    for mismatch in mismatches:
        if not isinstance(mismatch, dict):
            continue
        rows.append(
            "<tr>"
            f"<td>{html.escape(str(mismatch.get('fixture') or mismatch.get('stage') or 'unknown'))}</td>"
            f"<td>{html.escape(str(mismatch.get('owner', '@unknown')))}</td>"
            f"<td>{html.escape(str(mismatch.get('reason', 'mismatch')))}</td>"
            "</tr>"
        )

    patch_sections = []
    for name, patch in patches:
        patch_sections.append(
            f"<h3>{html.escape(name)}</h3><pre>{html.escape(patch)}</pre>"
        )

    return f"""<!doctype html>
<html>
<head>
  <meta charset=\"utf-8\" />
  <title>Golden Diff Report</title>
  <style>
    body {{ font-family: -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif; margin: 24px; color: #111; }}
    h1,h2,h3 {{ margin: 0 0 12px 0; }}
    table {{ border-collapse: collapse; width: 100%; margin: 12px 0 24px; }}
    td,th {{ border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 14px; }}
    pre {{ background: #0b1020; color: #e6edf3; padding: 14px; overflow-x: auto; border-radius: 6px; }}
    .ok {{ color: #0b7a3f; }}
    .fail {{ color: #a60000; }}
  </style>
</head>
<body>
  <h1>Golden Diff Report</h1>
  <p>Result: <strong class=\"{'ok' if result == 'success' else 'fail'}\">{result}</strong></p>
  <h2>Mismatches</h2>
  <table>
    <thead><tr><th>Fixture/Stage</th><th>Owner</th><th>Reason</th></tr></thead>
    <tbody>
      {''.join(rows) if rows else '<tr><td colspan="3">No mismatches listed.</td></tr>'}
    </tbody>
  </table>
  <h2>Diffs</h2>
  {''.join(patch_sections) if patch_sections else '<p>No patch files in archive.</p>'}
</body>
</html>
"""


def main() -> int:
    parser = argparse.ArgumentParser(description="Extract golden diff zip into readable HTML")
    parser.add_argument("zip_path", help="Path to golden-diff.zip")
    parser.add_argument("--out", default="artifacts/golden-diff.html", help="Output HTML path")
    args = parser.parse_args()

    summary, patches = extract(args.zip_path)
    rendered = to_html(summary, patches)

    os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
    with open(args.out, "w", encoding="utf-8") as fh:
        fh.write(rendered)

    print(f"Rendered HTML: {args.out}")
    print(f"Patch sections: {len(patches)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
