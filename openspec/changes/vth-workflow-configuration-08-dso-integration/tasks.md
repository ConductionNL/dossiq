# Tasks: vth-workflow-configuration-08-dso-integration


> **Build status (hydra audit 2026-06-10).** `lib/Service/DsoIntakeService::processAanvraag()`, `lib/Service/DsoCaseService::createZaakFromVergunningaanvraag()/transitionStatus()/computeDeadline()/authorizeZaakMutation()`, `lib/Service/DsoLvAuthService` + `DsoController` + `DSOIntakeController` ship on dev. End-to-end DSO bus wiring + status-push back are integration concerns (cross-app); admin DSO settings tab is greenfield UI.
DSO intake, status pushback, deadline tracking. Traces to giant Tasks 14, 15, 16.

## 1. Intake and Case Mapping

- [ ] Implement `DsoIntakeService.mapVerzoekToCase(...)` mapping STAM 2.0 fields (activiteiten, locatie, initiatiefnemer, procedureType, bijlagen)
- [ ] Resolve BRP/organization references; download and attach bijlagen via FileService
- [ ] Implement `DsoCaseService.createCaseFromVerzoek(...)`
- [ ] Create `VergunningaanvraagCreatedListener` on ObjectCreatedEvent; flag manual linking on BRP failure
- [ ] Register the listener in `appinfo/info.xml`
- [ ] Test case creation, data mapping, BRP success/failure, and bijlagen attachment

## 2. Status Pushback

- [ ] Create `VergunningStatusChangedEvent` (vergunningaanvraagRef, old/new status, besluitdatum, toelichting, userId, beschikkingUrl)
- [ ] Create `StatusChangeDispatcherListener`; include beschikking URL for Verleend/Geweigerd
- [ ] Register the listener in `appinfo/info.xml`
- [ ] Test event dispatch and payload, and OpenConnector consumption

## 3. Deadline Tracking

- [ ] Implement `DsoDeadlineService.evaluateDeadlines()` with OW working-day deadline calculation (8/26 weeks)
- [ ] Implement 6-week/2-week warning thresholds and overdue flagging/escalation
- [ ] Create `DsoDeadlineJob` (TimedJob, daily); register in `appinfo/info.xml`
- [ ] Test deadline calculation, warning triggers, and overdue flagging
