---
retrofit: true
---

# Consultation Management Specification

## Purpose

Provide first-class lifecycle management for inter-departmental consultations (adviesaanvragen) — separate entities linked to a parent zaak, with their own create/list/status-transition/advice-submission/overdue-detection surface — so that a casehandler can request structured advice from another department, the advising department can respond with one of four codified outcomes, and the system can highlight requests that have passed their deadline.

## Requirements

### REQ-001: Consultation REST endpoints (index, create, updateStatus, submitResponse, overdue)

The system SHALL expose five `@NoAdminRequired` JSON endpoints on `ConsultationController` — `index(caseId)`, `create()`, `updateStatus(id)`, `submitResponse(id)`, `overdue()` — that wrap `\RuntimeException` payloads as HTTP 400 `{error: <message>}` and delegate all business logic to `ConsultationService`.

#### Scenario: Read endpoints

- WHEN `index(caseId)` or `overdue()` is called
- THEN the controller SHALL return `{results: [...]}` with the service's response (empty array on OpenRegister unavailable, not an error)

#### Scenario: Write-endpoint envelope

- WHEN `create`, `updateStatus`, or `submitResponse` is called
- THEN the controller SHALL read `getContent()`, JSON-decode it (falling back to `[]` on non-array), delegate, and catch `\RuntimeException` returning HTTP 400 `{error: <message>}`
- AND `create` SHALL return HTTP 201 on success

### REQ-002: Consultation creation with required-field guards

The system SHALL require both `parentZaak` (the case the consultation belongs to) and `adviesInstantie` (the department or organisation being asked) on creation, default `status` to `open`, stamp `createdAt` with ISO 8601 timestamp, and persist via OpenRegister.

#### Scenario: Missing required field

- WHEN `createConsultation` is called with empty `parentZaak` or empty `adviesInstantie`
- THEN the service SHALL throw `\RuntimeException('parentZaak is required')` or `\RuntimeException('adviesInstantie is required')` before invoking OpenRegister

#### Scenario: OpenRegister unavailable

- WHEN OpenRegister is unavailable, the consultation schema is unconfigured, or the register id is empty
- THEN the service SHALL throw `\RuntimeException('OpenRegister is not available')` or `\RuntimeException('Consultation schema not configured')`

#### Scenario: Success shape

- WHEN creation succeeds
- THEN the service SHALL return `{id: <new uuid>, status: 'open'}` and log `'Consultation created: <uuid> for case <parentZaak>'`

### REQ-003: Consultation status lifecycle with afgesloten timestamp

The system SHALL accept status transitions only to one of `open`, `in_behandeling`, `advies_uitgebracht`, `afgesloten`. When the target status is `afgesloten`, the service SHALL stamp `closedAt` with the current timestamp before persisting.

#### Scenario: Reject invalid status

- WHEN `updateStatus` is called with a status outside the enum
- THEN the service SHALL throw `\RuntimeException('Invalid status: <value>')` before contacting OpenRegister

#### Scenario: Closure stamps closedAt

- WHEN `updateStatus` transitions to `afgesloten`
- THEN the update payload SHALL include `closedAt = date('Y-m-d\TH:i:s')` and the persisted object SHALL carry both `status` and `closedAt`

#### Scenario: Other transitions update only status

- WHEN transitioning to `open`, `in_behandeling`, or `advies_uitgebracht`
- THEN the update payload SHALL contain only `{status: <new>}` and the service SHALL return `{id, status}`

### REQ-004: Advice response submission with enum validation

The system SHALL accept advice responses with `advies` constrained to `positief`, `positief_met_voorwaarden`, `negatief`, `niet_van_toepassing`, optional `toelichting` (free text), and optional `voorwaarden` (any structured payload, JSON-encoded for storage); on submission it SHALL stamp `adviesDatum` (today, date only) and transition `status` to `advies_uitgebracht`.

#### Scenario: Reject invalid advies enum

- WHEN `submitResponse` is called with `advies` not in the four-value enum
- THEN the service SHALL throw `\RuntimeException('Invalid advice type: <value>')` before contacting OpenRegister

#### Scenario: Conditions get JSON-encoded

- WHEN `voorwaarden` is supplied
- THEN the service SHALL `json_encode` it into the persisted record; absent input SHALL persist as `null`

#### Scenario: Success shape and side effect

- WHEN submission succeeds
- THEN the service SHALL set `adviesDatum = date('Y-m-d')`, set `status = 'advies_uitgebracht'`, log `'Consultation <id> advice submitted: <advies>'`, and return `{id, advies, status: 'advies_uitgebracht'}`

### REQ-005: Overdue consultation detection via uiterlijkeReactiedatum

The system SHALL surface consultations whose `uiterlijkeReactiedatum` is before today and whose status is still `open` or `in_behandeling`.

#### Scenario: Filter scope

- WHEN `getOverdueConsultations` runs
- THEN it SHALL fetch up to 200 consultations with `status=open` AND 200 with `status=in_behandeling`, merge them, and filter to those with non-empty `uiterlijkeReactiedatum < today (Y-m-d)`

#### Scenario: OpenRegister unavailable

- WHEN OpenRegister is unavailable or the schema is unconfigured
- THEN the service SHALL return an empty array (no error)

#### Notes

- The 200-item per-status cap is a hardcoded pagination limit; on instances with very large consultation backlogs this is observed-but-suspicious and may silently drop overdue items beyond that window — flagged for future tightening.
