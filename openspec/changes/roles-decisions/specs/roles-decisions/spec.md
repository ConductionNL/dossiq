---
status: proposed
---

# roles-decisions Specification (Change Delta)

## Purpose
Deliver the case-detail UI gaps for Roles & Decisions: a dedicated Decisions section with CRUD and validity-period display, archival-metadata-aware Result section, and field-level role validation. The canonical capability spec lives at `openspec/specs/roles-decisions/spec.md`; this delta narrows scope to the missing UI affordances and the role/decision linkage rules enforced in the frontend.

## Requirements

### Requirement: REQ-DECISION-001 Decisions section MUST support full CRUD from case detail
The case-detail view SHALL render a `DecisionsSection.vue` component that lists decisions linked to the current case and supports create, edit, and delete operations subject to permissions.

#### Scenario: Authorized role creates a decision
- GIVEN a case worker with role `handler` or `decision_maker` opens case `ZAAK-2026-000123`
- WHEN they click "Besluit toevoegen" in the Decisions section
- THEN a dialog MUST collect `title`, `description`, `decisionType`, `decidedBy`, `decidedAt`, `effectiveDate`, and `expiryDate`
- AND on save the decision object MUST be persisted via OpenRegister with `case` set to `ZAAK-2026-000123`
- AND the new decision MUST appear in the section list without a full page reload

#### Scenario: Unauthorized role cannot create a decision
- GIVEN a user whose only role on the case is `stakeholder`
- WHEN the case detail loads
- THEN the "Besluit toevoegen" action MUST be hidden
- AND the section MUST be read-only

### Requirement: REQ-DECISION-002 Decision validity period MUST be displayed and computed
Each decision row SHALL show its validity window based on `effectiveDate` and `expiryDate`, computed in `decisionHelpers.js`.

#### Scenario: Decision is currently valid
- GIVEN a decision with `effectiveDate` 2026-01-01 and `expiryDate` 2026-12-31
- WHEN the current date is 2026-05-11
- THEN the row MUST render a green "Geldig" badge with text "Geldig tot 31-12-2026"

#### Scenario: Decision has expired
- GIVEN a decision with `expiryDate` 2026-04-01
- WHEN the current date is 2026-05-11
- THEN the row MUST render a grey "Verlopen" badge with the expiry date

#### Scenario: Decision has no expiry
- GIVEN a decision with `effectiveDate` set and `expiryDate` empty
- THEN the badge MUST read "Geldig (onbepaalde tijd)"

### Requirement: REQ-DECISION-005 Decisions section MUST be present on every case detail view
The Decisions section SHALL appear on `CaseDetail.vue` between the Result section and the activity timeline regardless of whether decisions exist.

#### Scenario: Case has no decisions
- GIVEN case `ZAAK-2026-000123` has zero decisions
- THEN the section MUST render its header and an empty-state hint "Nog geen besluiten genomen"
- AND the create action MUST remain available to authorized roles

### Requirement: REQ-RESULT-001 Result section MUST display archival metadata
`ResultSection.vue` SHALL surface the archival action and retention information derived from the linked `resultType`.

#### Scenario: Result with retention rule
- GIVEN the case has a result whose `resultType` has `archiveAction` `retain` and `retentionPeriod` `P20Y`
- THEN the section MUST display: "Bewaartermijn: 20 jaar", "Archiefactie: bewaren", and the computed retention end date based on `retentionDateSource`

#### Scenario: Result with destroy action
- GIVEN `archiveAction` is `destroy` with `retentionPeriod` `P5Y`
- THEN the section MUST display "Archiefactie: vernietigen" with a tooltip explaining the disposal date

### Requirement: REQ-ROLE-006 Role validation MUST enforce field requirements per role type
When creating or editing a Role on a case, the form SHALL enforce required fields defined by the linked `roleType.genericRole`.

#### Scenario: Initiator requires participant
- GIVEN the user selects `roleType` whose `genericRole` is `initiator`
- WHEN they leave `participant` empty and submit
- THEN the form MUST block submission with error "Een initiator vereist een deelnemer"

#### Scenario: Decision-maker role gates decision creation
- GIVEN no user holds a role with `genericRole` `decision_maker` on the case
- WHEN any user attempts to open the "Besluit toevoegen" dialog
- THEN the dialog MUST display a warning "Geen besluitnemer toegewezen op deze zaak"
- AND the save action MUST be disabled until a decision_maker role is assigned

### Requirement: REQ-ROLE-007 Role-to-decision audit trail MUST be preserved
Every decision write SHALL record the actor's role at the moment of the action, so the audit history reflects which role authorized the decision even if the user's roles change later.

#### Scenario: Audit trail on decision create
- GIVEN user `alice` holds role `decision_maker` on case `ZAAK-2026-000123`
- WHEN alice creates a decision
- THEN the decision's audit entry MUST contain `actor: alice`, `actorRole: decision_maker`, and the timestamp
- AND a later removal of alice's `decision_maker` role MUST NOT alter the historical audit entry

#### Scenario: Audit trail on decision reversal
- GIVEN an existing decision is deleted by a `coordinator` role
- THEN the audit entry MUST record `action: delete`, `actor`, `actorRole: coordinator`, and the original decision payload for traceability
