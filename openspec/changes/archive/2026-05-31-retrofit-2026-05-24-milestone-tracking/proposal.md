# Retrofit — milestone-tracking

Describes observed behavior of 2 PHP files (~11 methods) — milestone controller and service — as 5 new REQs.

## Affected code units

- lib/Controller/MilestoneController.php (4 methods) — REST endpoints progress / mark / reverse
- lib/Service/MilestoneService.php (7 methods) — milestone definitions per case type, progress calculation, mark, reverse (with audit reason), duration analytics

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match observed behavior
- Note: an unarchived design change exists at `openspec/changes/milestone-tracking/`; this retrofit specs the **already-implemented slice**.

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
