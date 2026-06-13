# mandaat-matrix Specification — Member 06: Temporal Queries + Conflict of Interest

---
status: proposed
---

## Purpose

Resolve the mandate version effective on the decision date for authorization and audit, and block
decisions where the decision-maker has a conflict of interest.

## ADDED Requirements

### Requirement: Effective-Dated Mandate Resolution

The `MandaatQueryService` SHALL resolve the mandate version effective on a given decision date, and
authorization SHALL use that version.

#### Scenario: Authorization uses the version effective on the decision date

- GIVEN mandate M.3.1.1 v1 (plafond €50.000, effective through 2026-06-30) and v2 (plafond
  €100.000, effective from 2026-07-01)
- WHEN on 2026-06-25 a user attempts a decision with bouwsom €75.000
- THEN `getMandaatAsOf("M.3.1.1", 2026-06-25)` SHALL return v1
- AND the plafond check SHALL use €50.000, yielding `plafond_overschreden`
- AND the system SHALL offer to schedule the decision for 2026-07-01 or later to use v2

#### Scenario: Audit snapshot is not re-evaluated against later versions

- GIVEN a decision made 2026-03-15 using v1
- WHEN an auditor reviews the zaak on 2026-07-01 after v2 activated
- THEN the audit trail SHALL show v1 (plafond €50.000) as used
- AND the system SHALL NOT re-evaluate the decision against v2

### Requirement: Conflict of Interest Detection

The `ConflictOfInterestService` SHALL detect when a decision-maker is related to the applicant and
SHALL block such decisions, supporting both automatic BRP detection and manual registration.

#### Scenario: Automatic BRP conflict blocks the decision

- GIVEN a zaak whose applicant BSN is related (e.g. spouse) to the deciding user
- WHEN the user attempts a decision on this zaak
- THEN `checkConflict(userId, zaakId)` SHALL return `{conflict: true}`
- AND `isAuthorized()` SHALL return `{authorized: false, reden: "belangenconflict"}`
- AND an escalation to a different role holder SHALL be triggered

#### Scenario: Manual conflict registration blocks the decision

- GIVEN a user with no automatic conflict who registers one with a reason
- WHEN they submit "Register interest conflict"
- THEN the case SHALL record the conflict flag and reason
- AND the user SHALL be prevented from executing the decision
- AND an escalation to an alternative mandaathouder SHALL be triggered
