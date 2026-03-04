#!/usr/bin/env python3
"""
Optional helper for GitHub Actions.

Reads PR comments from GITHUB_EVENT_PATH and prints whether a fixture owner ACK was detected.
It does not call GitHub APIs itself. Use with github-script/gh CLI if Ops approves automation.
"""

from __future__ import annotations

import json
import os
import re
import sys
from typing import Dict


def owner_map(readme_path: str) -> Dict[str, str]:
    owners: Dict[str, str] = {}
    if not os.path.isfile(readme_path):
        return owners
    with open(readme_path, "r", encoding="utf-8") as fh:
        for line in fh:
            m = re.search(r"`([^`]+\.json)`.*`(@[^`]+)`", line)
            if m:
                owners[m.group(1)] = m.group(2)
    return owners


def main() -> int:
    event_path = os.environ.get("GITHUB_EVENT_PATH", "")
    if not event_path or not os.path.isfile(event_path):
        print("No GITHUB_EVENT_PATH; skipping")
        return 0

    with open(event_path, "r", encoding="utf-8") as fh:
        event = json.load(fh)

    comments = event.get("comments", [])
    if not isinstance(comments, list):
        comments = []

    owners = owner_map("app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/README.md")
    owner_handles = set(v.lower() for v in owners.values())

    acked = False
    for comment in comments:
        user = str(((comment or {}).get("user") or {}).get("login", "")).lower()
        body = str((comment or {}).get("body", "")).lower()
        if user and ("@" + user) in owner_handles and ("ack" in body or "approved" in body):
            acked = True
            print(f"Owner ACK detected from @{user}")
            break

    if acked:
        print("eligible_for_label=true")
        return 0

    print("eligible_for_label=false")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
