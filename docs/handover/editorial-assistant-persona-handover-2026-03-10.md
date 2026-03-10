# Editorial Assistant + Persona System Handover (2026-03-10)

## Session Goal
Finalize Editorial Assistant architecture and baseline prompt quality before implementing full persona builder, with strict attention to prompt-size safety.

## Current Git State
- Active branch: `chore/prompt-hardening-checkpoint`
- Last pushed checkpoint commit: `d9a10c8`
- Uncommitted working changes:
  - `app/public/wp-content/plugins/dual-gpt-wordpress-plugin/includes/class-author-agent.php`
  - `app/public/wp-content/plugins/dual-gpt-wordpress-plugin/includes/class-planner-orchestrator.php`

## What Was Completed

### 1) Menu / IA / Naming work
- Personas page moved under Editorial Assistant.
- "Preferences" renamed to "Personas".
- Preset naming updates introduced in code:
  - `research-default` display name -> `Generic`
  - `ep-editorial-planner` display name -> `Specialised`
- Existing preset updates changed to upsert behavior (update existing presets instead of insert-only), so renames can apply to existing DB rows when plugin code paths run.

### 2) Clarified baseline vocabulary
- Baselines are not personas.
- Active baseline roles in process:
  - `Content Author` (author baseline)
  - research baseline(s): `Generic` / `Specialised`
- `Framework Generator` identified as internal workflow preset, not user research profile.
- `Framework Generator` role changed from `research` to `framework` to avoid showing in research profile UI dropdown.

### 3) Research depth slider note
- Numeric spinner on slider persisted due WordPress RangeControl rendering behavior.
- Multiple attempts made; accepted as known UI issue to address in later reskin/version.

### 4) Baseline prompt hardening (major)
Author-side baseline was updated to enforce:
- Em-dash ban
- Reporter-only stance
- No first-person narrative
- Attributed recollection only for memory cues
- Banned phrase list (including “not X but Y” style patterns)
- Validation warnings for:
  - em dashes
  - first-person narration
  - banned phrasing
  - subheading limit/style checks
  - perspective coverage heuristic

Research-side baseline prompt instructions were strengthened to include:
- Source mix requirement (academic + analyst + industry media + case study)
- Citation uniqueness and org cap
- Failure/adoption-lag requirement

Then refined split:
- Perspective toggling + unresolved tension removed from research prompts and kept author-side.

## Architecture Findings (Important)

### Prompt-size risk
- Full custom GPT persona text is too large for direct injection.
- Measured doc sizes (chars):
  - FSI Research: 7913
  - FSI Writer: 6032
  - PIM Research: 5909
  - PIM Writer: 6493
  - PLL Research: 8264
  - PLL Writer: 6310

### Practical direction agreed
- Keep baseline prompts compact.
- Put heavy persona details into structured config.
- Compile short digest at runtime later.
- Enforce critical constraints server-side so quality does not depend on prompt size.

## Research-only Gap Assessment (Current)

### Already strong
- Multi-phase orchestration with structured JSON outputs.
- Focus/breadth control.
- Recency/date-verification instructions.
- Source-mix and citation-hygiene instructions in prompts.

### Still missing / not deterministic yet
- Server-side hard enforcement for:
  - source mix minimums
  - citation uniqueness
  - max citations per organization
  - blocked domains/keywords policy
- Persona policy object + digest compiler not implemented yet.

## Compacting Pass Status
- Prompt text compaction pass completed (wording compressed, intent preserved).
- No file errors after compaction edits.
- Net prompt literal reduction from that pass was measured as modest (~700 chars across affected literals), with behavior-focused constraints retained.

## Next Agreed Work (Do Next in New Chat)

### Immediate next step (research-only, cautious)
Implement lightweight server validators before persona digest wiring:
1. Validate saved research phase payloads server-side for:
   - source mix minimums
   - citation uniqueness
   - max 2 citations per org
   - failure/adoption-lag signal
2. Persist validation results in session meta (e.g. `research_validation`) for future UI surfacing.
3. Keep this as warnings/structured results first (avoid disruptive hard-fail on first pass).
4. Do **not** start persona digest wiring until validator outputs are reviewed.

### After that
- Define persona digest budget contract implementation with hard caps + deterministic trim order.
- Wire UI later so persona builder can show policy/validation feedback.

## Files Most Relevant to Continue
- `app/public/wp-content/plugins/dual-gpt-wordpress-plugin/includes/class-planner-orchestrator.php`
- `app/public/wp-content/plugins/dual-gpt-wordpress-plugin/includes/class-author-agent.php`
- `app/public/wp-content/plugins/dual-gpt-wordpress-plugin/includes/class-dual-gpt-plugin.php`
- `app/public/wp-content/plugins/dual-gpt-wordpress-plugin/includes/class-db-handler.php`
- `app/public/wp-content/plugins/khm-plugin/assets/js/editorial-new-session.js`

## Resume Prompt (copy into fresh chat)
"Continue from `docs/handover/editorial-assistant-persona-handover-2026-03-10.md`. Work research-only: add lightweight server-side validators for source-mix/citation-hygiene/failure-lag, persist structured validation in session meta, and do not wire persona digest yet. Keep changes small and reversible."