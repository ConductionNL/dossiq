# Retrofit — consultation-management

Describes observed behavior of 2 PHP files (~12 methods) — inter-departmental consultation (adviesaanvraag) controller and service — as 5 new REQs.

## Affected code units

- lib/Controller/ConsultationController.php (6 methods) — REST endpoints index / create / updateStatus / submitResponse / overdue
- lib/Service/ConsultationService.php (6 methods) — create, list-by-case, status transitions, advice submission, overdue-by-deadline detection

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match observed behavior
- Note: an unarchived design change `openspec/changes/consultation-management/` exists; this retrofit specifies the slice that is **already implemented** so the in-flight design change can layer additional scope on top.

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
