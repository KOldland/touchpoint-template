#!/usr/bin/env python3
from __future__ import annotations

import argparse
import html
import json
import os
import zipfile
from typing import Dict, List, Tuple


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


def owner_contacts(mismatches: List[dict]) -> Dict[str, List[str]]:
    contacts: Dict[str, List[str]] = {}
    for mismatch in mismatches:
        fixture = str(mismatch.get("fixture") or mismatch.get("stage") or "unknown")
        owner = str(mismatch.get("owner", "@unknown"))
        contacts.setdefault(owner, []).append(fixture)
    for owner, fixtures in list(contacts.items()):
        contacts[owner] = sorted(set(fixtures))
    return contacts


def repro_steps(mismatch: dict) -> str:
    fixture = str(mismatch.get("fixture") or "")
    if fixture.endswith(".json"):
        return (
            "./scripts/ci_local_env.sh\n"
            f"php scripts/dev_golden_check.php --fixture {fixture} --output artifacts/dev-golden-check"
        )
    stage = str(mismatch.get("stage") or "runtime")
    return (
        "./scripts/ci_local_env.sh\n"
        f"# reproduce stage-level issue\n"
        f"php scripts/dev_golden_check.php --output artifacts/dev-golden-check  # focus on {stage}"
    )


def to_html(summary: dict, patches: List[Tuple[str, str]]) -> str:
    result = html.escape(str(summary.get("result", "unknown")))
    run_id = html.escape(str(summary.get("run_id") or summary.get("runId") or "unknown"))
    base = html.escape(str(summary.get("base", "unknown")))
    head = html.escape(str(summary.get("head", "unknown")))
    duration = html.escape(str(summary.get("duration_ms", "n/a")))

    mismatches_raw = summary.get("mismatches", []) if isinstance(summary, dict) else []
    mismatches: List[dict] = [m for m in mismatches_raw if isinstance(m, dict)]

    rows = []
    for mismatch in mismatches:
        fixture_stage = str(mismatch.get("fixture") or mismatch.get("stage") or "unknown")
        owner = str(mismatch.get("owner", "@unknown"))
        reason = str(mismatch.get("reason", "mismatch"))
        diff_file = str(mismatch.get("diff_file", ""))
        rows.append(
            "<tr>"
            f"<td>{html.escape(fixture_stage)}</td>"
            f"<td>{html.escape(owner)}</td>"
            f"<td>{html.escape(reason)}</td>"
            f"<td>{html.escape(diff_file)}</td>"
            "</tr>"
        )

    contacts = owner_contacts(mismatches)
    owner_blocks = []
    for owner, fixtures in contacts.items():
        owner_blocks.append(
            f"<li><strong>{html.escape(owner)}</strong>: {html.escape(', '.join(fixtures))}</li>"
        )

    repro_blocks = []
    for mismatch in mismatches:
        target = str(mismatch.get("fixture") or mismatch.get("stage") or "unknown")
        repro_blocks.append(
            f"<h4>{html.escape(target)}</h4><pre>{html.escape(repro_steps(mismatch))}</pre>"
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
    h1,h2,h3,h4 {{ margin: 0 0 12px 0; }}
    table {{ border-collapse: collapse; width: 100%; margin: 12px 0 24px; }}
    td,th {{ border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 14px; }}
    pre {{ background: #0b1020; color: #e6edf3; padding: 14px; overflow-x: auto; border-radius: 6px; }}
    .ok {{ color: #0b7a3f; }}
    .fail {{ color: #a60000; }}
    .meta {{ background: #f6f8fb; border: 1px solid #e0e6ef; padding: 10px; border-radius: 6px; margin: 12px 0; }}
  </style>
</head>
<body>
  <h1>Golden Diff Report</h1>
  <p>Result: <strong class=\"{'ok' if result == 'success' else 'fail'}\">{result}</strong></p>
  <div class=\"meta\">
    <div><strong>Run ID:</strong> {run_id}</div>
    <div><strong>Base:</strong> {base}</div>
    <div><strong>Head:</strong> {head}</div>
    <div><strong>Duration (ms):</strong> {duration}</div>
  </div>

  <h2>Mismatches</h2>
  <table>
    <thead><tr><th>Fixture/Stage</th><th>Owner</th><th>Reason</th><th>Diff File</th></tr></thead>
    <tbody>
      {''.join(rows) if rows else '<tr><td colspan="4">No mismatches listed.</td></tr>'}
    </tbody>
  </table>

  <h2>Owner Contacts</h2>
  <ul>
    {''.join(owner_blocks) if owner_blocks else '<li>@ci-qa-team</li>'}
  </ul>

  <h2>Quick Reproduction</h2>
  {''.join(repro_blocks) if repro_blocks else '<pre>./scripts/ci_local_env.sh\nphp scripts/dev_golden_check.php --output artifacts/dev-golden-check</pre>'}

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
