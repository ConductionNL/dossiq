# Retrofit — advice-management

Describes observed behavior of 3 PHP files (~18 methods) — advice controller, service, deadline background job — as 3 new REQs.

## Affected code units
- lib/Controller/AdviceController.php (4 methods) — advice transitions + reminders
- lib/Service/AdviceService.php (12 methods) — advice lifecycle + workflow guard
- lib/BackgroundJob/AdviceDeadlineJob.php (2 methods) — deadline reminders + auto-expiry

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
