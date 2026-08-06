# compliance-and-tenant-fixes — resumed from WIP-preserve, shipped 2026-07-16

PRs: **#237** (apply, merged fc240225d) · **#239** (archive, merged c19dfe1f3)
Issues: **#223 CLOSED** · **#229 OPEN** (feature work, deliberately not built)
Archived: `openspec/changes/archive/2026-07-16-compliance-and-tenant-fixes/`

## Pre-built (WIP 0934fadb2, 12 files, untested) vs finished here

**Pre-built and kept:** billing chain (`runInvoicing`), Shillinq DI factory, go-live tier
line, 2 billing routes, `create`/`updateStatus` audit wiring, bezwaar AST rewrite,
non-zero invoice test.
**Finished here:** the actual durable audit write + honest attestation (the WIP only
reworded the claim), AWB null-safety, test-mirror de-drift, subsidie decision +
downgrade, all openspec artifacts (change dir never existed), @spec anchors, gates,
baseline-diffed verification, merge of a moved `origin/development`.

## Per-finding verdict

1. **False attestation — REAL, worse than reported. FIXED.** `emit()` wrote only a log
   line (no durable sink), AND the cited callers didn't exist. `hardeningChecklist()`
   itself had zero callers. Now writes a hash-chained OR audit row via
   `AuditTrailMapper::createAuditTrailEntry` (ADR-022 — chosen over a `tenantAuditEntry`
   schema, which would be a mutable register with no chain). Checklist entries carry
   `status`; audit claim probed live, fails closed to `unverified`; pen-test → `unverified`.
2. **AWB — REAL. FIXED + 2 more defects found.** Dialect verified against OR
   origin/development read-only (or#433/#435 didn't change the contract; ARRAY form was
   inert). Found: (a) `dateAdd` returns null on non-numeric amount → ordinary bezwaar got
   a **null statutory deadline**; coalesced to 0. (b) the test mirror cast `(int)null`→0,
   more lenient than the engine → green suite, dead prod. Mirror de-drifted.
3. **Subsidie — REAL, unbuilt. SPEC DOWNGRADED, not built.** All 4 methods zero-callers;
   `approveReport()` sets status only. Needs Docudesk PDF/A + TAM-register product
   decisions → `done` → `partial`. Rejected wiring `isVoorschotReleasable()` blind (it's
   a financial disbursement).

## Test output (php:8.3-cli container)

- Baseline origin/development 2e96ec64b: **1666 tests, 4 errors, 5 skipped**
- Branch: **1681 tests, 5584 assertions, 4 errors, 5 skipped** → **+15 new, all green**
- 4 errors **pre-existing**: failing test NAMES diffed vs a pristine baseline worktree,
  same filter both sides → **byte-identical** (`ZipArchive` ext missing from the bare
  container; not a regression). Names: `BeschikkingServiceTest::testAuditPacketIsZip`,
  `ZipManifestBuilderTest::{testBuildZipDeduplicatesFilenames,ExcludesAboveClearance,HasManifestAndTypeFolders}`.

Key assertions:
- `testEmitWritesADurableAuditRow` — 1 row, anchored to tenant, action
  `procest.tenant.tenant.provisioned`. **Verified FAILS pre-fix**: `Failed asserting that false is true`.
- `testAuditClaimFailsClosedWhenSinkUnavailable` → `unverified`; `...PassesWhenSinkAvailable` → `pass`.
- `testRunInvoicingComputesNonZeroAmountAndExports` → **EUR149.00**, `exported:true`, `INV-2026-07-t1`.
- AWB: base `2026-02-12`; +42 verdaging +7 opschorting → `2026-04-02`; dwangsom EUR0 grace /
  EUR567 @21d / EUR1442 capped. `testDecisionDeadlineSurvivesAbsentOptionalFields` **fails
  without coalesce**: `Failed asserting that null is not null`.

## Spec downgraded

`openspec/specs/subsidieverlening-keten/spec.md`: **`done` → `partial`** (was justified by
"capability code confirmed present on development" — the orphaned-capability fallacy).

## Gotchas hit

- **`origin/development` MOVED mid-task** (277f3117d → 2e96ec64b; PR #236 spec-anchor-repair
  merged by a parallel agent into the shared `.git`). `git diff origin/development HEAD`
  showed **237 files** — the inverse of *their* work, not pollution. Verified via per-commit
  file counts before merging. Merged clean → back to 18 files.
- `@spec` anchored at canonical `openspec/specs/`, never a change dir (breaks on archive).
- `openspec archive` synced the MODIFIED delta cleanly (`~ 1 modified`) — the delta named an
  existing canonical requirement header exactly.
- vendor was healthy (no dangling `vendor/nextcloud/ocp/OCP` symlink); baseline seeded by
  copying vendor after confirming composer.json/lock identical.
- Root-owned `.phpunit.cache` blocks `rm -rf` on baseline worktrees → remove via alpine container.
