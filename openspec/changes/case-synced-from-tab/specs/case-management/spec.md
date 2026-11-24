## ADDED Requirements

### Requirement: A synchronized case shows where it came from

`CaseDetail` SHALL carry a tab "Synced from" that reads `GET /apps/integriq/api/provenance` for the case and shows the source, the source record, the last synchronization, the owner of the fields and the latest log entries, with a link to the synchronization in Integriq. The tab SHALL show only while that endpoint answers `synced: true`.

#### Scenario: a case from the case system

@e2e exclude needs integriq and a synchronization run into dossiq; covered by tests/vitest/caseSyncedFromTab.spec.js.

- **GIVEN** a case that integriq's synchronization "Zaken naar register" wrote from ZAAK-2026-0082
- **WHEN** a case handler opens the case
- **THEN** a "Synced from" tab names the source and ZAAK-2026-0082 and lists the latest log entries
