# Retrofit — leges-fees

Describes observed behavior of 3 PHP files (~24 methods) — leges API controller, calculation rules engine, and export service — as 5 new REQs.

## Affected code units

- lib/Controller/LegesController.php (6 methods) — `calculate`, `recalculate`, `verrekening`, `teruggaaf`, `export` JSON endpoints with try/catch wrapping
- lib/Service/LegesCalculationService.php (11 methods) — rules engine for vast / percentage / staffel / maximum / combinatie types with 2-decimal precision
- lib/Service/LegesExportService.php (7 methods) — CSV / ASCII / XML (StUF-FIN) export to financial systems

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match observed behavior

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
