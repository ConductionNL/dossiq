# Retrofit — vth-workflow-templates

Adds 1 new REQ describing the observed behavior of the `SeedVthWorkflowTemplates` repair step — the install-time mechanism that seeds the VTH workflow catalog into OpenRegister.

The existing spec already enumerates the four workflow template catalogs (Omgevingsvergunning / Toezichtzaak / Handhavingszaak / VTH library). This REQ documents the install-time seeding contract: idempotent per template title, deterministic IDs, graceful no-op when OpenRegister or the catalog directory is missing.

## Affected code units
- lib/Repair/SeedVthWorkflowTemplates.php (1 public `run()` method, 9 private helpers) — `run()`, `processCatalogFile()`, `resolveCaseTypeId()`, `isAlreadySeeded()`, `buildStatusMap()`, `resolveSteps()`, `resolveTransitions()`, `deterministicId()`, `extractFirstId()`, `normalizeRow()`

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
