---
status: proposed
---

# Spec: avg-verwerkingenlogging

**Status:** proposed
**Scope:** procest
**Depends on:** case-management, zgw-autorisaties-api (client config adjacency), openregister (object store + RBAC, per ADR-022)
**Standard:** VNG Logging Verwerkingen (verwerkingenlogging API-standaard); AVG art. 5 lid 2, art. 6, art. 15, art. 30

## Purpose

AVG processing-activity logging for Procest, distinct from the object audit trail: every
processing of personal data (including reads) produces an append-only log entry attributed to a
registered verwerkingsactiviteit carrying purpose, legal basis, and recipients, queryable by the
FG per betrokkene and exposed via a VNG Logging Verwerkingen-shaped API.

## ADDED Requirements

### Requirement: Processing activities MUST be maintained in a register

The system SHALL provide an admin-managed register of processing activities (verwerkingsactiviteiten), each carrying name, purpose (doel), AVG legal basis (rechtsgrond) with statutory reference, data-subject categories, recipients, retention period, and a confidentiality flag.

#### Scenario: Admin registers a processing activity

- **GIVEN** an administrator opens the Verwerkingsactiviteiten settings tab
- **WHEN** they create the activity "Behandelen omgevingsvergunning" with purpose, legal basis `publicTask`, statutory reference "Omgevingswet art. 5.1", data-subject categories, recipients, and a retention period
- **THEN** a `processingActivity` object MUST be created in OpenRegister with all supplied fields
- **AND** the activity MUST be selectable for case-type attribution

#### Scenario: Legal basis is mandatory and constrained

- **WHEN** a processing activity is submitted without a legal basis, or with a value outside the six AVG art. 6 grounds
- **THEN** the request MUST be rejected with a validation error

#### Scenario: Deactivating an activity preserves history

- **GIVEN** an activity with existing log entries
- **WHEN** the administrator deactivates it
- **THEN** the activity MUST no longer be selectable for new attribution
- **AND** existing log entries referencing it MUST remain intact and resolvable

### Requirement: Every processing of personal data MUST produce a log entry

The system SHALL write a `processingLogEntry` for every read, create, update, delete, and export of person-bearing case data, recording action, action name, the attributed processing activity, performing user or client, channel, timestamp, and the processed objects with identifier type and value (e.g. BSN), per the VNG Logging Verwerkingen model. Reads SHALL be logged even though the object audit trail does not cover them.

#### Scenario: Reading a case with a BSN-bearing betrokkene is logged

- **GIVEN** a case of type "Omgevingsvergunning" whose initiator carries a BSN
- **WHEN** handler Jan opens the case detail
- **THEN** a `processingLogEntry` MUST be recorded with `action = read`, `performedBy = jan`, `channel = ui`, the case reference, and `processedObjects` containing `{objectType: persoon, idType: BSN, idValue: <bsn>}`
- **AND** the entry MUST reference the verwerkingsactiviteit attributed to the case type

#### Scenario: Mutations are logged in addition to the audit trail

- **WHEN** Jan updates the same case
- **THEN** a `processingLogEntry` with `action = update` MUST be recorded
- **AND** the existing OpenRegister object audit trail entry MUST be unchanged in form and content (the two records coexist)

#### Scenario: ZGW API access is logged with the client identity

- **GIVEN** an external system holds a ZGW bearer token
- **WHEN** it retrieves a zaak with person data via the ZRC endpoint
- **THEN** a `processingLogEntry` MUST be recorded with `channel = zgw-api` and `performedBy` set to the client identifier

#### Scenario: Export is a distinct logged action

- **WHEN** a user exports a case dossier containing person data
- **THEN** a `processingLogEntry` with `action = export` MUST be recorded

#### Scenario: Logging never blocks the primary action

- **GIVEN** the log flush backend is temporarily unavailable
- **WHEN** a handler opens a case
- **THEN** the case MUST load normally
- **AND** the pending log entries MUST be spooled and flushed when the backend recovers
- **AND** a persistent flush failure MUST raise an administrator warning rather than dropping entries silently

### Requirement: Processing MUST be attributable to an activity, with a visible fallback

Each case type SHALL be mappable to a default processing activity and each ZGW API client to a per-client activity; processing that matches no mapping SHALL be attributed to a seeded, flagged fallback activity that is surfaced to the FG, so that no processing is ever silently unlogged.

#### Scenario: Case-type attribution

- **GIVEN** the case type "Omgevingsvergunning" is mapped to activity "Behandelen omgevingsvergunning"
- **WHEN** any processing occurs in the context of a case of that type
- **THEN** the log entry MUST reference that activity

#### Scenario: Unmapped processing hits the flagged fallback

- **GIVEN** a case type without an activity mapping
- **WHEN** a handler reads a person-bearing case of that type
- **THEN** the log entry MUST be attributed to the seeded fallback activity "Niet-geclassificeerde verwerking"
- **AND** the FG dashboard MUST show the unclassified count so the mapping gap can be fixed

### Requirement: The processing log MUST be append-only with enforced retention

Log entries SHALL be immutable through the application (no update or delete endpoints), readable only by the FG role and administrators per OR RBAC, retained for a configurable period (default 3 years per the VNG norm), and hard-deleted by a background job after retention; entries belonging to a confidential activity SHALL be visible exclusively to the FG role.

#### Scenario: Entries cannot be modified or deleted via the app

- **WHEN** any user, including an administrator, attempts to update or delete a `processingLogEntry` through the API
- **THEN** the request MUST be rejected (no such endpoint / RBAC denial)

#### Scenario: Retention job removes expired entries

- **GIVEN** the retention period is configured at 3 years
- **WHEN** the retention background job runs
- **THEN** entries older than 3 years MUST be permanently removed
- **AND** the job run itself MUST produce a log entry recording the purge

#### Scenario: Confidential activity entries are FG-only

- **GIVEN** an activity flagged `confidential` (e.g. fraud investigation)
- **WHEN** a non-FG administrator queries the log, or a betrokkene export is generated
- **THEN** entries of that activity MUST be excluded from the results
- **AND** an FG-role user MUST see them

### Requirement: The FG MUST be able to query and export the log per betrokkene

The system SHALL provide an FG inquiry view filtering log entries by data-subject identifier (e.g. BSN), period, activity, performing user, and channel, and SHALL export the result — including each entry's activity purpose and legal basis — to support an AVG art. 15 inzageverzoek.

#### Scenario: Query by BSN

- **GIVEN** an FG-role user opens the verwerkingenlog inquiry view
- **WHEN** they filter on a citizen's BSN and the period January–June 2026
- **THEN** they MUST see every log entry whose `processedObjects` contains that BSN within the period, with timestamp, action, performer, channel, and activity

#### Scenario: Inzageverzoek export

- **WHEN** the FG exports that filtered result
- **THEN** the export MUST include, per entry, the activity name, purpose, and legal basis alongside the access details
- **AND** the export action itself MUST produce a log entry

#### Scenario: Non-FG users cannot reach the inquiry surface

- **WHEN** a regular handler attempts to open the inquiry view or call its endpoints
- **THEN** access MUST be denied by OR RBAC

### Requirement: A VNG Logging Verwerkingen-shaped API MUST be exposed

The system SHALL expose bearer-gated REST endpoints shaped after the VNG Logging Verwerkingen API standard for listing and filtering verwerkingsacties and processing activities, with the same authentication posture as `zgw-autorisaties-api`, so external privacy/audit tooling can consume the log.

#### Scenario: External audit tool lists verwerkingsacties

- **GIVEN** an external tool holds a valid bearer token with the verwerkingenlogging scope
- **WHEN** it requests the verwerkingsacties endpoint filtered by period and betrokkene identifier
- **THEN** it MUST receive the matching entries in the standard's resource shape, paginated
- **AND** confidential-activity entries MUST be excluded unless the token carries the FG scope

#### Scenario: Unauthenticated access is rejected

- **WHEN** the endpoint is called without a valid bearer token
- **THEN** the response MUST be 401 and no log content is disclosed
