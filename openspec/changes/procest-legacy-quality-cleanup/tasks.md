# Tasks: Procest Legacy Quality Cleanup

> Implementation note (hydra-build 2026-06-06): the proposal's premise was
> partly stale, and a prior build (2026-06-03) took a weaker baseline-it path.
> This build burns the debt DOWN instead:
> - **phpcs.xml** has NO legacy-debt `<exclude-pattern>` block — only the
>   standard vendor/node_modules/template infra excludes. The "3 excluded
>   files" in the original Phase 2 never existed; phpcs is already green.
> - **phpmd.baseline.xml** (301 entries) was entirely STALE — it suppressed
>   none of the 19 current violations. All 19 were FIXED and the baseline was
>   DELETED. PHPMD now passes clean with no baseline.
> - **phpstan-baseline.neon** (42 entries) was stale. Of 57 leaking errors,
>   23 real issues were fixed, 26 stub-precision false positives moved to
>   documented `phpstan.neon` ignoreErrors, and the baseline was regenerated
>   to 14 entries (a single documented category: injected-but-unused DI props).
> - **composer.json** phpmd script de-baselined; **README** updated; the
>   unified gate wired into Codeberg CI (`.forgejo/workflows/`).

## Phase 1 — Inventory + planning

- [x] Run `composer phpcs` — already green (0 errors; only non-failing @spec
      warnings). Only infra `<exclude-pattern>` entries exist (no source debt).
- [x] Run `composer phpmd` — 19 violations (11 MissingImport, 4 LongVariable,
      3 BooleanArgumentFlag, 1 CyclomaticComplexity); stale baseline suppressed
      none of them.
- [x] Run `composer phpstan` — 57 errors against an empty baseline.
- [x] Decide per gate: PHPMD 19 < 50 → FIX ALL + delete baseline. PHPSTAN →
      fix 23 real, ignore 26 stub FPs, baseline 14 tracked DI-prop entries.
- [x] Confirm CI runs `composer check:strict` (added Codeberg
      `pre-merge-check-strict` workflow; `.github` already runs psalm+phpstan).

## Phase 2 — PHPCS burn-down

- [x] No legacy-debt source `<exclude-pattern>` exists in phpcs.xml — nothing
      to drop; gate already clean (N/A x3).
- [x] phpcs stays green (0 errors) after all code edits (one FunctionSpacing
      error from a constant removal was auto-fixed via phpcbf).

## Phase 3 — PHPMD burn-down (FIX-OUTRIGHT, baseline DELETED)

- [x] MissingImport (11) — added `use DateTimeImmutable/DateTimeInterface/
      InvalidArgumentException;` + dropped leading backslashes in
      ConflictDetectionService, DailySyncService, EvidenceMetadataService,
      SyncBackoffService.
- [x] LongVariable (4) — `$tussenrapportageService`→`$tussenrapportage`,
      `$syncQueueReplayService`→`$replayService`,
      `$conflictDetectionService`→`$conflictService`,
      `$slowConnectionWarning`→`$slowLinkWarning` (payload key string kept).
- [x] CyclomaticComplexity (1) — extracted `resolveRegisterConfig()` from
      `SubsidieRegisterController::collectEntries()`.
- [x] BooleanArgumentFlag (3) — rule-sanctioned
      `@SuppressWarnings(PHPMD.BooleanArgumentFlag)` with justification on three
      intentional, documented boolean toggles (not a rule weaken).
- [x] Deleted `phpmd.baseline.xml`; dropped `--baseline-file` from composer.json
      phpmd script. PHPMD passes clean with NO baseline.

## Phase 4 — PHPStan burn-down (fix real, ignore stub FPs, slim baseline)

- [x] Dead constants (13) removed — 11 `ERR_*` (WorkflowDefinitionService),
      `MAX_ATTACHMENT_SIZE` (BerichtenboxService), `VALID_COMPONENT_FILES`
      (CaseDefinitionImportService).
- [x] Real logic fixes:
  - [x] `SeedVthWorkflowTemplates::resolveTransitions()` — `$fromId might not
        be defined` fixed by initialising `$fromId='*'` + restructuring.
  - [x] `RoleResolverService` — removed redundant `$hops` counter (its `>=1`
        check was always-true); loop now breaks unconditionally after one hop.
  - [x] `WmsWfsService::fetchAllLayers()` — dropped dead `?? 0` on the
        non-nullable `getConfigValue()` return.
- [x] Stub-precision false positives (26) → documented `phpstan.neon`
      ignoreErrors: AuthorizedAdminSetting class-string (16),
      registerEventListener/IJobList::add class-string (4), IEventListener
      @implements subtype (1), method_exists('\OC_Util',…) (1), defensive
      is_array()/getUploadedFile guards (CaseDefinitionController + Seed).
- [x] `phpstan-baseline.neon` slimmed 42 → 14 (single documented category:
      injected-but-unused DI properties) with a header pointing at the
      OR-abstraction adoption work that will remove them.
- [x] Gate runs clean: `phpstan analyse` → `[OK] No errors`.

## Phase 5 — CI integration

- [x] `composer check:strict` runs on every PR via new
      `.forgejo/workflows/pre-merge-check-strict.yaml` (`runs-on:
      codeberg-small` — served label, NOT the unserved `docker`).
- [x] `phpmd.baseline.xml` deleted (PHPMD clean).
- [x] `phpstan-baseline.neon` slimmed + documented.
- [x] No source-file excludes remain in `phpcs.xml`.
- [x] DEFERRED: weekly `check:strict` smoke-test cron — the per-PR gate gives
      equivalent coverage; a standalone cron belongs to a fleet CI change.

## Phase 6 — Documentation

- [x] Updated README quality-gates section (unified `check:strict`, no PHPMD
      baseline, documented slim PHPStan baseline).
- [x] DEFERRED: app-config.json note — README + this tasks.md are the record.
- [x] DEFERRED: close the burn-down tracking issue — maintainer does this on
      merge.
