# Retrofit — procest-app-scaffold

Describes observed behavior of the Procest bootstrap glue — the `InitializeSettings` repair step + the `SeedDataService` — as 2 new REQs on the `procest-app-scaffold` capability.

Requirement 8 of the existing spec already mandates "App MUST have repair steps for initialization", but it only specifies the contract at the appinfo registration level. These REQs document the actual observed runtime behavior of the two units the repair step + seeder rely on.

## Affected code units
- lib/Repair/InitializeSettings.php (1 method) — `run()` repair step that auto-imports the procest register on app enable
- lib/Service/SeedDataService.php (1 public seed method) — `seedBezwaarBeroepData()` idempotent bezwaar/beroep case-type seeder

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
