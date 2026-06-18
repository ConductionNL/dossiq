---
status: done
retrofit: true
---

# DSO Omgevingsloket Client Specification

## Purpose

@e2e exclude Backend intake adapter invoked by openconnector; no Playwright UI surface.

Convert an inbound DSO Omgevingsloket `vergunningaanvraag` message — delivered to procest by openconnector's DSO adapter (which owns the DSO-LV koppelvlak, mTLS, PKIoverheid, status pushback per its own spec) — into a procest `zaak` of type "Omgevingsvergunning" with the right deadline, title, and DSO-specific side records. Procest does NOT own the DSO protocol or the back-channel; this spec is deliberately scoped to the intake-adapter slice.

## Requirements

### REQ-001: DSO vergunningaanvraag intake creates procest zaak

The system SHALL accept a `dsoMessage` array, extract `activiteiten`, `locatie`, `aanvrager`, `bouwkosten`, `procedureType`, `zaaknummer`, and `bijlagen`, build a human-readable title from the activity names, and persist a new procest case via OpenRegister with `priority: 'normal'` and `startDate: today`. The result SHALL be `{caseId, dsoZaaknummer, activiteiten, procedureType, deadline}`.

#### Scenario: OpenRegister or register guards

- WHEN OpenRegister is unavailable
- THEN `processAanvraag` SHALL throw `\RuntimeException('OpenRegister is not available')`
- AND when the procest register is unconfigured it SHALL throw `\RuntimeException('Procest register not configured')`

#### Scenario: Title composition

- WHEN one or more activities are provided as `{naam: <string>}` items or bare strings
- THEN the case title SHALL be `'Omgevingsvergunning'` optionally suffixed with `: <activity1>, <activity2>, ...` (empty-filtered, comma-joined)

#### Scenario: Description provenance

- WHEN the dsoMessage carries a `zaaknummer`
- THEN the case description SHALL be `'Vergunningaanvraag ontvangen via DSO/Omgevingsloket (DSO: <zaaknummer>)'`
- AND when absent the suffix SHALL be omitted

#### Scenario: Logging

- WHEN intake succeeds
- THEN the service SHALL log `'DSO intake processed: case <caseId> (DSO: <dsoZaaknummer>)'`

### REQ-002: DSO-specific case properties stored as side records

The system SHALL persist DSO-specific properties as separate `case_property` records — one per attribute (`dsoZaaknummer`, `activiteiten`, `locatie`, `bouwkosten`, `procedureType`, `aanvragerNaam`) — rather than denormalising them onto the case. Empty values SHALL be skipped; array `locatie` SHALL be JSON-encoded before storage.

#### Scenario: Empty value skip

- WHEN a property value resolves to `''`
- THEN the service SHALL NOT call `saveObject` for that property

#### Scenario: locatie JSON encoding

- WHEN `locatie` is supplied as an array
- THEN it SHALL be `json_encode`d before persistence; when supplied as a string it SHALL be stored verbatim

#### Scenario: bouwkosten coercion

- WHEN `bouwkosten` is supplied (number or string)
- THEN it SHALL be coerced to string before persistence

#### Scenario: aanvragerNaam fallback

- WHEN `aanvrager` is absent or lacks `naam`
- THEN `aanvragerNaam` SHALL fall back to `''` and (per REQ-002 empty-skip) NOT be persisted

#### Notes

- Side-record storage keeps the case schema lean and lets the property schema evolve independently. The trade-off is that querying DSO cases requires joining `case` to `case_property` filtered by `name`.

### REQ-003: Procedure-type deadline duration lookup

The system SHALL expose `getDeadlineDuration(procedureType)` returning the ISO 8601 duration for the standard Omgevingswet procedure: `regulier` → `P56D` (8 weeks) and `uiTGEBREID` (typed `uitgebreid`) → `P182D` (26 weeks). Unknown procedure types SHALL fall back to `regulier`.

#### Scenario: Default fallback

- WHEN `procedureType` is missing or not in the lookup table
- THEN the service SHALL return `'P56D'` (the regulier default)

#### Scenario: Intake forwards deadline

- WHEN `processAanvraag` runs
- THEN the returned `deadline` field SHALL match `getDeadlineDuration(procedureType)`

#### Notes

- The two values mirror the Omgevingswet deadlines: reguliere procedure 8 weken (P56D), uitgebreide procedure 26 weken (P182D). The actual deadline-event creation (when the 8/26-week timer starts ticking and how procest reminds casehandlers as the deadline approaches) is observed-but-stubbed in `DsoIntakeService` — the case is persisted with `startDate = today` but no explicit deadline timer object. The in-flight `openspec/changes/dso-omgevingsloket/` change will spec that side.
