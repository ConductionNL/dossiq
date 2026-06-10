# Tasks: vth-workflow-configuration-08-dso-integration


> **Build status (hydra audit 2026-06-10).** `lib/Service/DsoIntakeService::processAanvraag()`, `lib/Service/DsoCaseService::createZaakFromVergunningaanvraag()/transitionStatus()/computeDeadline()/authorizeZaakMutation()`, `lib/Service/DsoLvAuthService` + `DsoController` + `DSOIntakeController` ship on dev. End-to-end DSO bus wiring + status-push back are integration concerns (cross-app); admin DSO settings tab is greenfield UI.
DSO intake, status pushback, deadline tracking. Traces to giant Tasks 14, 15, 16.

## 1. Intake and Case Mapping

- [x] Implement `DsoIntakeService.mapVerzoekToCase(...)` (verified on dev: `lib/Service/DsoIntakeService::processAanvraag()` maps STAM 2.0 fields)
- [x] Resolve BRP/organization references; download and attach bijlagen via FileService (verified on dev: DsoIntakeService BRP lookup + bijlagen attach)
- [x] Implement `DsoCaseService.createCaseFromVerzoek(...)` (verified on dev: `DsoCaseService::createZaakFromVergunningaanvraag()`)
- [x] Create `VergunningaanvraagCreatedListener` on ObjectCreatedEvent (verified on dev: `lib/Listener/VergunningaanvraagCreatedListener.php`)
- [x] Register the listener via `IRegistrationContext::registerEventListener()` in `Application::register()` (info.xml has no `<event-listeners>` element)
- [~] Test case creation, data mapping, BRP success/failure, and bijlagen attachment (deferred to vth-workflow-configuration-10-testing)

## 2. Status Pushback

- [x] `VergunningStatusChangedEvent` shipped on dev (this change: added optional `beschikkingUrl` constructor parameter + getter for Verleend/Geweigerd transitions)
- [x] `StatusChangeDispatcherListener` created in this change (`lib/Listener/StatusChangeDispatcherListener.php`) — listens for `VergunningStatusChangedEvent` and propagates the new status + optional beschikkingUrl back to the OR vergunningaanvraag object
- [x] Listener registered via `IRegistrationContext::registerEventListener()` in `Application::register()`
- [~] Test event dispatch and payload, and OpenConnector consumption (deferred to vth-workflow-configuration-10-testing)

## 3. Deadline Tracking

- [x] Implement `DsoDeadlineService.evaluateDeadlines()` (verified on dev: `lib/Service/DsoCaseService::computeDeadline()` performs OW working-day deadline math; the daily job wraps it)
- [x] Implement 6-week/2-week warning thresholds and overdue flagging/escalation (verified on dev: `lib/BackgroundJob/DsoDeadlineJob.php` warning/overdue paths)
- [x] Create `DsoDeadlineJob` (TimedJob, daily); register in `appinfo/info.xml` (this change: added `<job>OCA\Procest\BackgroundJob\DsoDeadlineJob</job>` to `appinfo/info.xml`)
- [~] Test deadline calculation, warning triggers, and overdue flagging (deferred to vth-workflow-configuration-10-testing)
