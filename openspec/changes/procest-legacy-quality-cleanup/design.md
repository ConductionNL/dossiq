# Procest Legacy Quality Cleanup — Design

## Overview

This is a tracking change for hardening Procest's quality gates. The work is spec-only; no new entities or major architectural changes are introduced. The change captures a burn-down process for clearing legacy exclude-patterns and establishing baseline-free gates for PHPCS, PHPMD, and PHPStan.

## Process Methodology

### Phase-Based Burn-Down

The change is organized into six sequential phases, each with clear entry/exit criteria:

1. **Inventory + Planning** — Run all three gates for the first time as a unified block; capture baseline counts and violation categories.
2. **PHPCS Burn-Down** — File-by-file cleanup of the 3 excluded files; drop each exclude-pattern once fixed.
3. **PHPMD Burn-Down** — Contingent on Phase 1 findings; either fix-outright (if <50 violations) or manage via baseline.xml.
4. **PHPStan Burn-Down** — Contingent on Phase 1 findings; same trade-off.
5. **CI Integration** — Verify `composer check:strict` runs on every PR; remove baselines once complete.
6. **Documentation** — Update README and close tracking issue.

### Decision Points

**Phase 1 Inventory Decision:** Per gate, decide whether to fix-outright (if <50 violations) or capture a fresh baseline.xml and burn down incrementally.

**Phase 2-4 Scope:** Only fix violations that the sniff requires. No refactoring beyond what's necessary to pass the gate. Docblock additions are expected to be minimal (1-2 lines per function signature).

**Phase 5 Milestone:** Once all baselines reach 0 lines, delete baseline files and drop legacy-debt section from phpcs.xml.

## Quality Gates Integration

The unified gate is invoked via `composer check:strict`, which runs all three tools in sequence:
```bash
composer check:strict
  ├── composer phpcs
  ├── composer phpmd
  └── composer phpstan
```

This gate runs on every PR via CI before merge. The gate is green only when all three tools report zero violations (or pass within baseline thresholds during burn-down).

## Exclude-Pattern Strategy

### Current State
- **phpcs.xml:** 3 exclude-patterns in a legacy-debt section, no additional baselines.
- **phpmd.xml:** Configured but no baseline.xml exists; gate has never been unified-run.
- **phpstan:** No baseline-neon exists; gate has never been unified-run.

### Target State
- All exclude-patterns dropped from phpcs.xml.
- No baseline.xml or baseline-neon files in the repo (all violations fixed).
- Gate runs clean (`composer check:strict` returns 0) on every PR.

## Implementation Notes

### File-by-File Tracking

Each of the 3 excluded PHPCS files gets its own PR to simplify review and bisect later if regressions surface:

```
Phase 2 PR 1: Excluded file 1 — fix sniffs + drop exclude
Phase 2 PR 2: Excluded file 2 — fix sniffs + drop exclude
Phase 2 PR 3: Excluded file 3 — fix sniffs + drop exclude
Phase 2 PR 4 (final): Drop legacy-debt section from phpcs.xml
```

### Baseline Handling

If PHPMD/PHPStan surface >50 violations during Phase 1:
- Generate phpmd.baseline.xml or phpstan-baseline.neon.
- Burn down in logical slices (e.g., by violation type or file group) in subsequent phases.
- Each burn-down PR reduces baseline violations by a measurable slice.

If <50 violations:
- Fix all violations in a single PR; skip baseline creation entirely.

### CI Configuration

The `.github/workflows/ci.yml` is updated to run `composer check:strict` as a required check before merge. No changes to individual gate invocations; they continue to run in CI but are now consumed by the unified gate.

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| PHPMD/PHPStan surface >500 violations | Phase 1 decision will capture baseline and spread burn-down over 2-3 sprints. Start with the highest-ROI violations (e.g., missing types, unused parameters). |
| Exclude-pattern removal causes unexpected failures | Each file is tested in isolation; if a sniff fix is wrong, it's caught in the PR-specific test run before merge. |
| CI integration is incomplete | Phase 1 includes a checkpoint: confirm CI runs `composer check:strict` and gates PRs before proceeding to burn-down phases. |
| Baseline file edits conflict with merge automation | Baseline files are ephemeral; once burn-down reaches 0, they are deleted. Use squash merges to keep commit history clean. |

## References

- **Audit Source:** `.claude/audit-2026-05-03/03-repo-hygiene.md` (in OpenRegister repo)
- **Hydra ADR-022:** Apps consume OR abstractions (quality conventions)
- **composer.json:** `check:strict` script definition and per-tool script references
- **CI Target:** `.github/workflows/ci.yml` (quality gate configuration)
