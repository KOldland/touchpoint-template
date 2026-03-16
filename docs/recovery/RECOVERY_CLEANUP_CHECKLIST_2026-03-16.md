# Recovery Cleanup Checklist - 2026-03-16

Branch: recovery/final-build-rebuild-20260316
Baseline: origin/staging
Checkpoint commit: 502cf32b

## 1) VS Code Clean State

- Open only the recovery workspace folder:
  - /Users/krisoldland/Local Sites/touchpoint-template-recovery
- Ensure Source Control points to:
  - branch recovery/final-build-rebuild-20260316
- Confirm no mixed workspace edits from:
  - touchpoint-template-remote
  - touchpoint-template-final-build

Verification:
- git status --short returns no changes in recovery repo.

## 2) Local Server Clean State

Goal: Final-build runtime reflects recovery branch code.

Safe sync scope:
- CHANGELOG.md
- check_deps.php
- composer.json
- phpunit.xml
- test_deps.php
- app/
- artifacts/
- bin/
- conf/
- devops/
- docs/
- migrations/
- observability/
- runbooks/
- scripts/
- tests/
- tools/

After sync:
- hard refresh wp-admin post editor
- verify score panel is visible in post sidebar
- verify GEO score appears
- verify SEO score appears

## 3) WPE Staging Clean State

Deploy source:
- branch recovery/final-build-rebuild-20260316

Smoke checks on staging:
- Post editor loads without critical errors.
- Visibility Scores panel appears in sidebar.
- GEO score matches post list GEO score source.
- SEO score appears and updates after audit/apply.
- SEO Agent audit completes.
- SEO Agent apply updates fields and score badges.

Record outputs:
- tested post IDs
- timestamp and user
- pass/fail with screenshot links

## 4) GitHub Clean State

- branch pushed:
  - origin/recovery/final-build-rebuild-20260316
- open PR:
  - base: staging
  - compare: recovery/final-build-rebuild-20260316
- require CI and smoke checks before merge
- tag merge commit as recovery checkpoint after approval

## 5) Immediate Command Runbook

```bash
# recovery repo sanity
cd "/Users/krisoldland/Local Sites/touchpoint-template-recovery"
git status --short
git branch --show-current

# local final-build sync from recovery
SRC="/Users/krisoldland/Local Sites/touchpoint-template-recovery/"
DST="/Users/krisoldland/Local Sites/touchpoint-template-final-build/"
for p in CHANGELOG.md check_deps.php composer.json phpunit.xml test_deps.php app artifacts bin conf devops docs migrations observability runbooks scripts tests tools; do
  [ -e "$SRC$p" ] && rsync -a "$SRC$p" "$DST"
done
```

## 6) Sign-off Matrix

- VS Code workspace clean: [ ]
- Local server synced from recovery: [ ]
- Local editor GEO/SEO verified: [ ]
- WPE staging deployed: [ ]
- WPE smoke suite passed: [ ]
- PR opened to staging: [ ]
- Recovery merge approved: [ ]
