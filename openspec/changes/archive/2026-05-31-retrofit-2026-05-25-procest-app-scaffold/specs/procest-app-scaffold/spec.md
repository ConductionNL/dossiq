---
retrofit_extensions:
  - REQ-013
  - REQ-014
---

# Procest App Scaffold — bootstrap repair + seed (retrofit)

## Requirements

### REQ-013: InitializeSettings repair step SHALL auto-import the procest register on app enable, gracefully no-op when OpenRegister is unavailable

`OCA\Procest\Repair\InitializeSettings` SHALL implement `IRepairStep` and SHALL run on every app enable / upgrade. The `run(IOutput $output)` method SHALL:
- Short-circuit with a warning when `SettingsService::isOpenRegisterAvailable()` returns false — never throw.
- Otherwise invoke `SettingsService::loadConfiguration(force: true)` to re-import `procest_register.json` and auto-configure every schema/register ID.
- Emit `$output->info(...)` on success (including the imported version), `$output->warning(...)` on a `success: false` envelope, and `$output->warning(...)` + `LoggerInterface::error(...)` on any uncaught `\Throwable`.
- NEVER propagate exceptions back to the repair runner — a Procest install must succeed even when the import partially fails (per the canonical Repair-step pattern documented in MEMORY.md).

#### Scenario: OpenRegister missing -> graceful warning
- **GIVEN** the `openregister` app is not installed
- **WHEN** `InitializeSettings::run()` executes during `occ app:enable procest`
- **THEN** the step SHALL emit a `$output->warning("OpenRegister is not installed...")`
- **AND** SHALL emit a logger warning
- **AND** SHALL return without invoking `SettingsService::loadConfiguration()`

#### Scenario: Successful import reports version
- **GIVEN** OpenRegister is available and `procest_register.json` is valid
- **WHEN** the repair step runs
- **THEN** `loadConfiguration(force: true)` SHALL be invoked
- **AND** on `success: true` the step SHALL emit `Procest configuration imported successfully (version: <X>)`

#### Scenario: Uncaught throwable does not break the repair runner
- **GIVEN** `loadConfiguration()` raises `\Throwable` mid-import
- **WHEN** `InitializeSettings::run()` runs
- **THEN** the step SHALL catch the throwable, emit `$output->warning('Could not auto-configure Procest: ...')`, log an error, and return — never propagate.

#### Notes
- Per MEMORY.md ("Import OR register via Repair step, NOT Migration"): this is the only place in Procest's install flow that touches OpenRegister registers. The legacy migration-time import path silently skipped because peer-app autoloaders weren't loaded yet.

### REQ-014: SeedDataService SHALL idempotently seed bezwaar/beroep case types from a bundled JSON template

`OCA\Procest\Service\SeedDataService::seedBezwaarBeroepData()` SHALL:
- Read seed data from `lib/Settings/bezwaar_seed_data.json` (the path is `__DIR__.'/../Settings/bezwaar_seed_data.json'` relative to the service).
- Return `{success: false, message: 'Seed data file not found'}` if the file is missing.
- Return `{success: false, message: 'Invalid JSON in seed data file'}` on JSON parse failure.
- Return `{success: false, message: 'ObjectService not available'}` if `getObjectService()` returns null.
- Return `{success: false, message: 'Register or schemas not configured'}` if `register` or `case_type_schema` IAppConfig keys are empty.
- Otherwise iterate `seedData.caseTypes[]`, delegating each entry to `seedCaseType(...)` with `caseType_schema`, `statusType_schema`, `roleType_schema`, and `workflow_template_schema` references, and SHALL aggregate per-type counts (caseTypes / statusTypes / roleTypes / workflows / skipped) into the result summary.
- Be IDEMPOTENT — every nested writer SHALL check for existing objects by identifier before creating, incrementing the `skipped` counter on collision.

#### Scenario: Missing seed file returns structured failure
- **GIVEN** `lib/Settings/bezwaar_seed_data.json` does not exist
- **WHEN** `seedBezwaarBeroepData()` is called
- **THEN** the method SHALL return `{success: false, message: 'Seed data file not found'}`
- **AND** SHALL log an error

#### Scenario: Empty register config returns structured failure
- **GIVEN** the `register` IAppConfig key is empty
- **WHEN** `seedBezwaarBeroepData()` is called
- **THEN** the method SHALL return `{success: false, message: 'Register or schemas not configured'}`

#### Scenario: Re-running the seeder is idempotent
- **GIVEN** `seedBezwaarBeroepData()` has already been run successfully (4 caseTypes created)
- **WHEN** the method is called a second time
- **THEN** the result SHALL have `success: true` and `caseTypes: 0`, with `skipped: 4`

#### Notes
- The service intentionally lives outside the InitializeSettings repair step so admins can also re-seed on demand (e.g. after restoring an empty register).
