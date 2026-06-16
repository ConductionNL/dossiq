---
status: proposed
---

# Spec: avg-verwerkingenlogging

**Status:** proposed
**Scope:** procest (thin consumer)
**Depends on:** `openregister/processing-activity-register` (OR-PA-1..9) — BLOCKED_EXTERNAL; case-management; zgw-autorisaties-api (client identity adjacency)
**Standard:** VNG Logging Verwerkingen; AVG art. 5 lid 2, art. 6, art. 15, art. 30

## Purpose

Procest contributes its zaakgericht-werken processing-activity catalogue, read-logging opt-ins,
attribution mappings, and FG UI surfacing to OpenRegister's platform verwerkingenlogging
capability. All storage, logging, retention, export, and API mechanics are owned by OpenRegister
per the 2026-06-11 abstraction decision; procest implements no log engine of its own.

## ADDED Requirements

### Requirement: Procest MUST declare its processing-activity catalogue declaratively

Procest SHALL ship its verwerkingsactiviteiten as `x-openregister-processing` annotations in its register configuration — per activity: name, purpose (doel), AVG art. 6 legal basis with statutory reference, data-subject categories, recipients, and retention period — seeded as drafts for FG review per OR-PA-2. Case-type attribution SHALL be expressed in the same annotations; procest SHALL NOT maintain a parallel activity store.

#### Scenario: Catalogue import seeds draft activities

- **GIVEN** procest's register configuration is imported into OpenRegister
- **WHEN** the import completes
- **THEN** each annotated verwerkingsactiviteit MUST exist in the OR processing-activity register as a draft
- **AND** activating it (FG action in OR) MUST make it attributable to procest case types

#### Scenario: Legal basis is constrained at the source

- **WHEN** a catalogue annotation declares a legal basis outside the six AVG art. 6 grounds
- **THEN** the OR import MUST reject the annotation with a validation error (OR-PA-2)
- **AND** the procest configuration MUST be corrected rather than bypassing validation

### Requirement: Person-bearing schemas MUST opt into read logging with attribution

Procest SHALL enable `logReads` on its person-bearing schemas (case, betrokkene/rol, klantcontact) so OpenRegister logs reads (OR-PA-3), and SHALL map each case type to its default activity via the catalogue annotations. ZGW API client identity SHALL reach OR's log context so machine access is attributed per client; processing without a mapping lands in OR's flagged fallback (OR-PA-4).

#### Scenario: Case read is logged by the platform with procest attribution

- **GIVEN** the case type "Omgevingsvergunning" is mapped to "Behandelen omgevingsvergunning"
- **WHEN** a handler opens a BSN-bearing case of that type
- **THEN** OpenRegister MUST record a read processing-log entry attributed to that activity
- **AND** procest MUST NOT write any log entry itself

#### Scenario: ZGW client access carries client identity

- **GIVEN** an external system reads a zaak via the ZRC endpoint with a bearer token
- **WHEN** OR records the processing-log entry
- **THEN** the entry's performer MUST reflect the resolved ZGW client identity (not a generic system user)

#### Scenario: Unmapped case type surfaces as unclassified

- **WHEN** processing occurs for a case type without an activity mapping
- **THEN** OR MUST attribute it to the flagged fallback (OR-PA-4)
- **AND** the procest FG view MUST show the unclassified count for procest's registers

### Requirement: Procest MUST surface the FG inquiry and inzageverzoek entry points

Procest SHALL provide an FG/admin view scoped to its register slice (OR-PA-8): catalogue review status, the unclassified-processing counter, and a per-betrokkene inzageverzoek export entry point that delegates to OR's export (OR-PA-7). Access SHALL follow OR's FG delegation; non-FG users are denied.

#### Scenario: FG opens the procest verwerkingen overview

- **GIVEN** a user holding the FG delegation in OpenRegister
- **WHEN** they open procest's Verwerkingen view
- **THEN** they MUST see procest's activity catalogue status and unclassified counter, scoped to procest registers only

#### Scenario: Inzageverzoek export delegates to the platform

- **WHEN** the FG triggers a per-betrokkene export (e.g. by BSN) from the procest view
- **THEN** the export MUST be produced by OR-PA-7 scoped to procest's registers, including activity purpose and legal basis per entry
- **AND** the export action itself MUST be logged by OR

#### Scenario: Non-FG users cannot reach the surface

- **WHEN** a regular handler attempts to open the Verwerkingen view or its delegated endpoints
- **THEN** access MUST be denied per OR-PA-8

### Requirement: Procest MUST NOT duplicate platform verwerkingenlogging mechanics

Procest SHALL NOT implement log storage, append-only enforcement, retention jobs, export engines, or VNG Logging Verwerkingen API endpoints; external audit tooling SHALL be pointed at OpenRegister's VNG-shaped API (OR-PA-9) with procest's register scope, and procest documentation SHALL state this.

#### Scenario: No procest log endpoints exist

- **WHEN** the procest route table is inspected
- **THEN** no endpoint MUST exist that creates, queries, updates, or deletes processing-log entries
- **AND** docs/ MUST direct audit tooling to the OR VNG API with the procest register scope
