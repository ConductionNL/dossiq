# Design: vth-workflow-configuration-08-dso-integration

## Architecture

`kind: code` member (ADR-032). Event-driven integration glue (ADR-003) — external-system integration is inherently imperative (ADR-031 "when to NOT chain"). Cases and events use OpenRegister objects (ADR-001/ADR-022). Listeners/jobs registered via `appinfo/info.xml` (ADR-016).

## Service Layout

- `DsoIntakeService.mapVerzoekToCase(vergunningaanvraag)` maps STAM 2.0 fields (activiteiten, locatie/BAG, initiatiefnemer/BRP, procedureType, bijlagen), resolving references and downloading bijlagen via FileService.
- `DsoCaseService.createCaseFromVerzoek(...)` creates the case against the Omgevingsvergunning workflow (member 02).
- `VergunningaanvraagCreatedListener` on ObjectCreatedEvent → create case; on BRP failure, flag "Awaiting manual initiator linking".
- `VergunningStatusChangedEvent` + `StatusChangeDispatcherListener` dispatch on status transitions; Verleend/Geweigerd include the beschikking URL.
- `DsoDeadlineService.evaluateDeadlines()` + `DsoDeadlineJob` (TimedJob, daily): 8-week reguliere / 26-week uitgebreide deadlines (OW working days), 6/2-week warnings, overdue flag.

## Security (ADR-005)

Listeners run in the OpenRegister event context. The deadline job runs as a background job (system context). No user-facing `#[NoAdminRequired]` endpoints are added; outbound pushback is mediated by OpenConnector. Mapped external data is validated before case creation.
