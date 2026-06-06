# Tasks: Procest Legacy Quality Cleanup

> Implementation note (hydra-build 2026-06-03): the proposal's premise was
> partly stale. The actual repo state at build time was:
> - **phpcs.xml** has NO legacy-debt `<exclude-pattern>` block — only the
>   standard vendor/node_modules/template excludes. The "3 excluded files"
>   in Phase 2 do not exist, so Phase 2 is N/A (nothing to burn down).
> - **phpmd.baseline.xml** and **phpstan-baseline.neon** already existed but
>   were stale: 270 PHPMD violations and 19 PHPStan errors leaked past them
>   because code had changed since the baselines were captured.
> The cleanup therefore (a) fixed all 19 leaked PHPStan errors outright —
> several were real bugs — and regenerated an accurate phpstan baseline, and
> (b) regenerated an accurate phpmd baseline (>50 complexity/length
> violations, deferred to incremental refactor per the design's baseline rule).

## Phase 1 — Inventory + Planning

- [x] Run `composer phpcs`, `composer phpmd`, `composer phpstan` for the first time as unified gates; capture baseline counts and violation categories per gate (phpcs: 0 errors / 224 warnings — all @spec warnings, non-blocking; phpmd: 270 violations leaking a stale baseline; phpstan: 19 errors leaking a stale baseline)
- [x] Per gate, decide: fix-outright (phpstan: 19 < 50 → fixed outright; phpcs: already clean) or capture a fresh baseline.xml (phpmd: 270 ≫ 50 → fresh accurate baseline + incremental burn-down deferred)
- [x] Confirm CI runs the gates on every PR (`.github/workflows/code-quality.yml` runs phpcs/psalm/phpstan; see Phase 5 note re: phpmd composer-script gap)

## Phase 2 — PHPCS Burn-Down

For each excluded file: fix sniffs, remove `<exclude-pattern>` from phpcs.xml, verify gate stays green.

- [x] Excluded file 1 — N/A: no legacy-debt exclude-patterns exist in phpcs.xml
- [x] Excluded file 2 — N/A: no legacy-debt exclude-patterns exist in phpcs.xml
- [x] Excluded file 3 — N/A: no legacy-debt exclude-patterns exist in phpcs.xml
- [x] Drop the legacy-debt block from `phpcs.xml` entirely once all excludes are removed — N/A: no such block exists; phpcs is already green (0 errors)

## Phase 3 — PHPMD Burn-Down

Contingent on Phase 1 findings. If <50 violations, collapse to single fix-outright PR; otherwise manage via baseline.xml.

- [x] Regenerated an accurate `phpmd.baseline.xml` (304 lines) so the gate is green and now catches NEW regressions. The 270 captured violations are mostly Cyclomatic/NPath/ExcessiveClassComplexity and ExcessiveMethodLength across ~70 service/controller files — genuine refactors that risk behaviour change and are out of scope for a gate-hardening change.
- [ ] DEFERRED: Resolve CyclomaticComplexity / NPathComplexity / ExcessiveMethodLength violations (extract methods) — tracked for the OR-abstraction adoption spec; large, behaviour-affecting refactor
- [ ] DEFERRED: Resolve remaining ShortVariable / ErrorControlOperator / StaticAccess violations
- [ ] Once baseline reaches 0 lines: delete `phpmd.baseline.xml` and drop `--baseline-file` from composer.json's phpmd script — DEFERRED (composer.json is owned by the fleet template; not edited here)

## Phase 4 — PHPStan Burn-Down

Contingent on Phase 1 findings. If <50 violations, collapse to single fix-outright PR; otherwise manage via baseline-neon.

- [x] Inventoried 19 errors leaking the stale baseline (by file/type)
- [x] Fixed all 19 outright:
  - [x] `IGroupManager::isAdmin(uid:)` → positional (wrong named arg) in InspectionChecklistController
  - [x] `basename(filename:)` → `basename(path:)` (wrong named arg) in VTHTemplateService
  - [x] `#[AuthorizedAdminSetting(settings: Application::class)]` → `AdminSettings::class`; AdminSettings now implements `IDelegatedSettings` (4 controller methods + 2 controllers)
  - [x] Wired the unused `DSO_SECRET_KEY` constant into `validateSignature()` via `IAppConfig` (env fallback); removed dead `$secret` assignment in DSOIntakeController
  - [x] Removed unused `FEED_SCHEMA_MAP` constant (duplicated inline values) in RaadsinformatieFeedController
  - [x] Removed never-read injected deps: `IUserSession` (WOODeadlineService), `SettingsService` (WOORedactionService)
  - [x] Removed redundant `?? 'Onbekend'` on a guaranteed-present array key in LhsLookupService
  - [x] ADR-022: corrected fabricated `findObject(register:,schema:,id:)` → real `find($id, register:, schema:)` + entity normalisation in InspectionChecklistService; removed its now-unused `$checklistId` param (psalm UnusedParam)
  - [x] Added `registerJob` OCP-stub-gap ignore to phpstan.neon (mirrors the existing `registerRepairStep` entry; method exists at runtime)
- [x] Regenerated an accurate `phpstan-baseline.neon` (57 errors across 42 groups) reflecting current code
- [x] Confirm gate runs clean: `phpstan analyse` → `[OK] No errors`

## Phase 5 — CI Integration

- [x] CI runs the quality gates on every PR (`.github/workflows/code-quality.yml`)
- [ ] DEFERRED: the `composer phpmd` script invokes the global `phpmd` binary (not `./vendor/bin/phpmd`), so it silently no-ops in CI when phpmd isn't globally installed. Fixing this requires a composer.json edit, which is owned by the fleet template and intentionally not touched in this change. Tracked for the fleet composer-template sweep.
- [ ] DEFERRED: weekly `composer check:strict` smoke-test cron on `development` (CI workflow change, low ROI until the phpmd-script gap above is fixed)

## Phase 6 — Documentation

- [x] This tasks.md documents the cleared/clarified legacy debt and the corrected premises
- [ ] DEFERRED: README quality-gates section + app-config.json note (cosmetic; no functional gate impact)
- [ ] DEFERRED: close the burn-down tracking issue once the phpmd baseline reaches 0 (blocked on the deferred complexity refactor)
