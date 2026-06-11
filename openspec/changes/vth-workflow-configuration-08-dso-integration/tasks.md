# Tasks: vth-workflow-configuration-08-dso-integration

DSO intake, status pushback, deadline tracking. Traces to giant Tasks 14, 15, 16.

## 1. Intake and Case Mapping

- [x] Implement `DsoIntakeService.mapVerzoekToCase(...)` — `lib/Service/DsoIntakeService.php::processAanvraag` line 73 maps STAM 2.0 verzoek fields (activiteiten, locatie, initiatiefnemer, procedureType, bijlagen)
- [x] Resolve BRP/organization references; download and attach bijlagen via FileService — `DsoIntakeService::processAanvraag` resolves BRP via the organisatie ref and attaches bijlagen via FileService; flags manual linking on BRP failure
- [x] Implement `DsoCaseService.createCaseFromVerzoek(...)` — `lib/Service/DsoCaseService.php::createZaakFromVergunningaanvraag` line 110
- [x] Create `VergunningaanvraagCreatedListener` on ObjectCreatedEvent — `lib/Listener/VergunningaanvraagCreatedListener.php`
- [x] Register the listener in `appinfo/info.xml` — listener bound in `lib/AppInfo/Application.php::register()` per the procest pattern (event bus registration moved to AppInfo per ADR-004)
- [x] Test case creation, data mapping, BRP success/failure, and bijlagen attachment — `tests/Unit/Service/DsoIntakeServiceTest.php` + `DsoCaseServiceTest.php`

## 2. Status Pushback

- [x] Create `VergunningStatusChangedEvent` — emitted by `DsoCaseService::transitionStatus` line 207 via IEventDispatcher with the documented payload (vergunningaanvraagRef, old/new status, besluitdatum, toelichting, userId, beschikkingUrl)
- [x] Create `StatusChangeDispatcherListener` — listener consumes the event and POSTs to OpenConnector
- [x] Register the listener — bound in `Application.php::register()` per the procest pattern
- [x] Test event dispatch and payload, and OpenConnector consumption — `DsoCaseServiceTest::testStatusTransitionDispatchesEvent`

## 3. Deadline Tracking

- [x] Implement `DsoDeadlineService.evaluateDeadlines()` with OW working-day deadline calculation (8/26 weeks) — `DsoCaseService::computeDeadline` line 311 handles the OW algorithm; the daily evaluation lives on `DsoDeadlineJob`
- [x] Implement 6-week/2-week warning thresholds and overdue flagging/escalation — `DsoDeadlineJob` reads the case + deadline + threshold table and dispatches `dso-deadline-warning`/`dso-deadline-overdue` events
- [x] Create `DsoDeadlineJob` (TimedJob, daily); register in `appinfo/info.xml` — `lib/BackgroundJob/DsoDeadlineJob.php`; registered via `Application.php`
