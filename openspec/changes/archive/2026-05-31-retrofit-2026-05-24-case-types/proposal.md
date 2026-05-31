# Retrofit — case-types

Describes observed behavior of the case-type export/import surface (3 files / 14 methods) as 2 new REQs (REQ-CT-17, REQ-CT-18) extending the case-types capability.

## Affected code units
- lib/Controller/CaseDefinitionController.php (4 methods) — export/validate/import endpoints
- lib/Service/CaseDefinitionExportService.php (5 methods) — ZIP package builder
- lib/Service/CaseDefinitionImportService.php (5 methods) — ZIP package validator + importer

## Approach
- File-level survey
- Two REQs: (1) HTTP surface + ZIP export, (2) ZIP validation + import

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
