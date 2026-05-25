---
retrofit_extensions:
  - REQ-001
---

# VTH Workflow Templates — install-time seeder (retrofit)

## Requirements

### REQ-001: SeedVthWorkflowTemplates repair step SHALL idempotently seed the VTH workflow catalog from bundled JSON files

`OCA\Procest\Repair\SeedVthWorkflowTemplates` SHALL implement `IRepairStep` and SHALL run on every app enable / upgrade. The `run(IOutput $output)` method SHALL:
- Short-circuit with a warning when `SettingsService::isOpenRegisterAvailable()` returns false — never throw.
- Short-circuit with a warning when the bundled catalog directory (`SeedVthWorkflowTemplates::CATALOG_DIR`) does not exist — never throw.
- Glob the catalog dir for `*.json` files. If no files match, emit a warning and return.
- Iterate every catalog file, delegating to `processCatalogFile()` which returns one of `seeded` / `skipped` / `crossLink` / `failed`. Per-file Throwables SHALL be caught, logged with file basename + exception message, surfaced via `$output->warning()`, and counted in the `failed` bucket — they SHALL NOT propagate to the repair runner.
- After every file is processed, emit a single summary `$output->info()` line with all four counters.

The step SHALL be IDEMPOTENT: `isAlreadySeeded(string $caseTypeId, string $title)` SHALL check for an existing workflow row by (caseType + title) before inserting, and SHALL increment the `skipped` counter rather than re-create. Deterministic IDs SHALL be generated via `deterministicId(string $template, string $child)` so re-runs produce identical UUIDs and downstream references stay stable.

#### Scenario: OpenRegister missing -> graceful no-op
- **GIVEN** the `openregister` app is not installed
- **WHEN** `SeedVthWorkflowTemplates::run()` executes during `occ app:enable procest`
- **THEN** the step SHALL emit `$output->warning('OpenRegister is not available. Skipping VTH workflow templates seed.')`
- **AND** SHALL return without globbing the catalog directory

#### Scenario: Catalog directory missing -> graceful no-op
- **GIVEN** OpenRegister is installed but `CATALOG_DIR` does not exist (deleted by a misconfigured build)
- **WHEN** the repair step runs
- **THEN** the step SHALL emit a warning with the missing path
- **AND** SHALL return without touching OpenRegister

#### Scenario: Re-running the seeder is idempotent
- **GIVEN** the seeder has already run and 4 workflow templates exist
- **WHEN** the seeder runs again on app upgrade
- **THEN** the summary line SHALL report `4 skipped` and `0 seeded`
- **AND** no duplicate rows SHALL be inserted

#### Scenario: One bad catalog file does not block the rest
- **GIVEN** 4 catalog files exist, one of which contains invalid JSON
- **WHEN** the seeder runs
- **THEN** the bad file's exception SHALL be logged with `app=procest` + the file basename + the exception message
- **AND** the user-facing summary SHALL report `1 failed`
- **AND** the other 3 catalog files SHALL still be processed

#### Notes
- The 9 private helpers (`processCatalogFile`, `resolveCaseTypeId`, `isAlreadySeeded`, `buildStatusMap`, `resolveSteps`, `resolveTransitions`, `deterministicId`, `extractFirstId`, `normalizeRow`) are not separately observable — they support the single `run()` contract above. Splitting them into separate REQs would inflate the spec without adding testable surface.
- `crossLink` is reserved for templates that reference an unresolved caseType; the seeder logs the reference and counts it but does not block the run.
