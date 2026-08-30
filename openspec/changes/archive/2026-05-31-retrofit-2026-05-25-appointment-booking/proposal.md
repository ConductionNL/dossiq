# Retrofit — appointment-booking (new capability)

Mints a new `appointment-booking` capability spec describing observed behavior of the Procest appointment subsystem. Code already exists across 8 PHP files (controller pair, service orchestrator, pluggable backend interface + 3 implementations, reminder background job) — this change retroactively specifies it.

The cluster spans:
- Pluggable backend pattern (interface + JCC / Qmatic / Local impls)
- Internal `AppointmentController` for case-handler CRUD
- Public `PublicAppointmentController` for citizen-side token-based access
- `AppointmentService` orchestrator that ties backends to OpenRegister persistence
- `AppointmentReminderJob` background reminder dispatcher

## Affected code units
- lib/Service/AppointmentBackend/AppointmentBackendInterface.php (4 methods)
- lib/Service/AppointmentBackend/JccBackend.php / QmaticBackend.php / LocalBackend.php (4 methods each)
- lib/Controller/AppointmentController.php (5 action methods)
- lib/Controller/PublicAppointmentController.php (2 action methods: view, cancel)
- lib/Service/AppointmentService.php (6 public methods)
- lib/BackgroundJob/AppointmentReminderJob.php (1 run method via TimedJob)

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
