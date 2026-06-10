# Tasks: vth-workflow-configuration-08-dso-integration

DSO intake, status pushback, deadline tracking. Traces to giant Tasks 14, 15, 16.

## 1. Intake and Case Mapping

- [~] Implement `DsoIntakeService.mapVerzoekToCase(...)` mapping STAM 2.0 fields (activiteiten, locatie, initiatiefnemer, procedureType, bijlagen) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Resolve BRP/organization references; download and attach bijlagen via FileService — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `DsoCaseService.createCaseFromVerzoek(...)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `VergunningaanvraagCreatedListener` on ObjectCreatedEvent; flag manual linking on BRP failure — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Register the listener in `appinfo/info.xml` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test case creation, data mapping, BRP success/failure, and bijlagen attachment — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Status Pushback

- [~] Create `VergunningStatusChangedEvent` (vergunningaanvraagRef, old/new status, besluitdatum, toelichting, userId, beschikkingUrl) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `StatusChangeDispatcherListener`; include beschikking URL for Verleend/Geweigerd — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Register the listener in `appinfo/info.xml` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test event dispatch and payload, and OpenConnector consumption — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Deadline Tracking

- [~] Implement `DsoDeadlineService.evaluateDeadlines()` with OW working-day deadline calculation (8/26 weeks) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement 6-week/2-week warning thresholds and overdue flagging/escalation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `DsoDeadlineJob` (TimedJob, daily); register in `appinfo/info.xml` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test deadline calculation, warning triggers, and overdue flagging — deferred to downstream cycle / fleet-wide adoption (handoff)
