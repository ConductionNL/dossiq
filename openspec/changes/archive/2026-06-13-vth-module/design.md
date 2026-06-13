# Design: vth-module

status: pr-created

## Architecture

The VTH module sits as a vertical domain on top of the generic Procest case engine. It does NOT fork case-management; it composes existing primitives (case, statusType, propertyDefinition, role) into VTH-shaped templates and adds VTH-specific services for intake, checklists, advice, and enforcement.

### Service Layout

- `VTHTemplateService` — loads `lib/Settings/templates/vth-*.json` template files and activates them as zaaktypes in OpenRegister (parallels the WOO template-library pattern).
- `DSOIntakeService` — receives a STAM 2.0 payload (vergunningaanvraag) from OpenConnector and maps it onto an `omgevingsvergunning` case with linked initiator, bouwlocatie, activiteiten, and uploaded documents.
- `InspectionChecklistService` — CRUD on `inspectionChecklist` (admin) and per-case completion via `inspectionResult` records; exposes endpoints consumed by mobiel-inspectie.
- `AdviceService` — `requestAdvice()`, `submitAdvice()`, `cancelAdvice()`; each `adviceRequest` is linked to a case, has an `adviseur` user/group, deadline, status (open/reminded/received/overdue/cancelled), and feeds into the case timeline.
- `LhsLookupService` — pure lookup on the LHS 4x4 matrix (Beoordeling gedrag × Mogelijke gevolgen) returning the recommended interventieladder step.

### Data Model (OpenRegister Schemas, added to procest_register.json)

- `inspectionChecklist` — name, version, caseTypeRef, items[ref], active, validFrom.
- `checklistItem` — question, type (boolean/enum/text/photo), required, weight, parent (nesting).
- `inspectionResult` — case ref, checklist ref, completedBy, completedAt, answers[{itemRef, value, photoRef}].
- `adviceRequest` — case ref, requestedBy, adviseur, deadline, status, vraag, adviesText, addedToFile.
- `lhsMatrixCell` — gedragRow, gevolgColumn, interventieStep, description.

### API Surface (V1)

- `POST /api/vth/templates/{slug}/activate` — activate VTH template into the active register.
- `POST /api/vth/dso/intake` — DSO callback endpoint (signed payload).
- `GET/POST /api/vth/checklists` — admin CRUD.
- `POST /api/vth/cases/{id}/inspection-result` — submit checklist completion.
- `POST /api/vth/cases/{id}/advice-requests` — create advice request.
- `GET /api/vth/lhs/lookup?gedrag=X&gevolg=Y` — LHS lookup.

## Dependencies

- OpenConnector for DSO callback signature validation and inbound routing.
- OpenRegister for all data storage and schema validation.
- Existing Procest case engine for status transitions, timeline, deadlines.
- Future: mobiel-inspectie (checklist completion on tablet), legesberekening (fee on omgevingsvergunning), docudesk (besluit anonymisering).

## Out of Scope (V2+)

- Full LHS-driven sanctiebesluit generator.
- Automatic koppeling met BAG/BGT for objectreferentie verrijking.
- DSO outbound (publicatie kennisgeving) — handled by separate change.
- 4-ogen accordering op handhavingsbesluit.
