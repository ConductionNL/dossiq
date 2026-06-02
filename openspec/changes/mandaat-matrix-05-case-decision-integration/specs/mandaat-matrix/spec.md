# mandaat-matrix Specification — Member 05: Case Decision Integration + Audit Logging

---
status: proposed
---

## Purpose

Enforce the authorization check at the case decision point and record an immutable audit log for
every authorized decision.

## ADDED Requirements

### Requirement: Authorization Guard on Case Decisions

The system SHALL invoke `MandaatCheckService.isAuthorized()` before any case decision that has a
mandate requirement executes, blocking and escalating on denial and proceeding on success.

#### Scenario: Authorized decision proceeds and is logged

- GIVEN a case decision "Vergunning verlenen" requiring a mandate, attempted by a user who holds it
- WHEN the decision action is triggered
- THEN the `CaseDecisionActionListener` SHALL call `isAuthorized()` and receive `{authorized: true}`
- AND the decision SHALL proceed
- AND a MandaatGebruik log entry SHALL be created after the decision completes

#### Scenario: Unauthorized decision is blocked and escalated

- GIVEN a case decision requiring a mandate the user does not hold
- WHEN the decision action is triggered
- THEN the listener SHALL receive `{authorized: false}`
- AND the decision SHALL NOT execute
- AND an escalation SHALL be dispatched and an error returned to the UI offering escalation

#### Scenario: Decisions without a mandate requirement are unaffected

- GIVEN a case decision that has no mandate requirement
- WHEN the decision action is triggered
- THEN the listener SHALL allow it to proceed without an authorization check or MandaatGebruik log

### Requirement: Immutable MandaatGebruik Audit Log

The `MandaatGebruikService` SHALL create a write-once MandaatGebruik record per authorized
decision, snapshotting role, mandate, and conditions, and SHALL reject mutation attempts.

#### Scenario: Snapshot captured atomically on authorized decision

- GIVEN an authorized decision by Alice (role "Senior Vergunningverlener") using mandate M.3.1.2
- WHEN the MandaatGebruik entry is created
- THEN it SHALL record `zaakId`, `beslissingId`, `mandaatId`, `gemandateerdeId`,
  `rolOpMomentVanBesluit` (role snapshot), `beslissingType`, `beslissingTimestamp`,
  `bevoegdheidsCheckResult`, and `gebruikteVoorwaarden`
- AND the record SHALL be locked

#### Scenario: Update attempt is rejected

- GIVEN an existing MandaatGebruik record
- WHEN any client attempts to update or delete it via the API
- THEN the system SHALL respond 403 Forbidden and the record SHALL remain unchanged

#### Scenario: Audit trail queryable per zaak

- GIVEN a zaak with multiple logged decisions
- WHEN `getDecisionAuditTrail(zaakId)` is called
- THEN it SHALL return all MandaatGebruik entries for that zaak with their snapshots intact
