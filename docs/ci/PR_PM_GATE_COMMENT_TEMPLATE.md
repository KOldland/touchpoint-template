# PM Gate Comment Template

Copy/paste this into the PR comment when requesting PM sign-off.

```md
PM sign-off request — CIC gate package

Scope:
- Branch: `<branch-name>`
- Commit: `<commit-sha>`
- PR: `<pr-link>`

Validation completed:
- [ ] golden-check fast gate
- [ ] secret-scan
- [ ] release dry-run
- [ ] staging gate dry-run
- [ ] alert-fire dry-run
- [ ] (if configured) alert-fire live proof

Artifacts:
- Golden summary: `<path-or-link>`
- Golden diff HTML: `<path-or-link>`
- Smoke summary: `<path-or-link>`
- Release deploy dry-run: `<path-or-link>`
- Release gate summary: `<path-or-link>`
- Alert-fire summary: `<path-or-link>`
- Alert-fire run log (`alert_fire_run_<id>.json`): `<path-or-link>`
- CI triage report: `<path-or-link>`

Observability:
- CIC Health dashboard URL: `<url>`
- Membership Health dashboard URL: `<url>`
- Paid/Reconcile dashboard URL: `<url>`
- Release Health dashboard URL: `<url>`

Ops confirmations:
- [ ] Alerts enabled with P0/P1/P2 escalation
- [ ] Pager/Slack/email/ticket routes validated
- [ ] No secret/PII in payloads

Risk and blockers:
- `<none | list blockers with owner + ETA>`

PM requested decision:
- [ ] Approve staged rollout
- [ ] Block pending listed items
```
