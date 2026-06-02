# mandaat-matrix Specification — Member 03: Escalation Engine

---
status: proposed
---

## Purpose

Create escalations when authority is insufficient, route them to the correct mandaathouder,
support approval/rejection, and reroute open escalaties on personnel change.

## ADDED Requirements

### Requirement: Escalation Creation and Path Resolution

The `MandaatEscalatieService` SHALL create a `MandaatEscalatie` record routed to the next-higher
mandaathouder when authority is insufficient, and SHALL notify that recipient.

#### Scenario: Plafond overschrijding escalates to the next-higher mandaathouder

- GIVEN a zaak with bouwsom €250.000 and a user holding M.3.1.1 (plafond €50.000)
- WHEN `createEscalatie(zaakId, "vergunning_verlenen", userId, "plafond_overschreden")` is called
- THEN the service SHALL resolve M.3.1.3 (plafond €500.000) held by "Hoofd Vergunningverlening" as the path
- AND SHALL create a MandaatEscalatie with `status: "open"`,
  `escalatieReden: "plafond_overschreden"`, `escalatiePadEindigtBij` = the current Hoofd VV holder
- AND SHALL send a notification to that recipient

#### Scenario: Escalation routed when role does not hold the mandate

- GIVEN a user whose role does not hold the required mandate
- WHEN an escalation is created with reden "niet_bevoegd"
- THEN a MandaatEscalatie SHALL be created routed to the role holder one level higher in the hierarchy

### Requirement: Escalation Approval and Rejection

The `EscalatieApprovalService` SHALL allow the resolved mandaathouder to approve (executing the
decision and logging usage) or reject (cancelling) an open escalation, recording the outcome.

#### Scenario: Approval executes the decision and logs usage

- GIVEN an open escalation routed to Hoofd VV for a €250.000 vergunning
- WHEN the mandaathouder calls approve via `POST /api/mandate/escalatie/{id}/approve`
- THEN the service SHALL re-check that the approver holds the mandate
- AND SHALL execute the underlying decision
- AND SHALL set the escalation status to "goedgekeurd"
- AND SHALL create a MandaatGebruik log entry attributed to the mandaathouder
- AND SHALL notify the original initiator

#### Scenario: Rejection cancels without executing

- GIVEN an open escalation
- WHEN the mandaathouder calls reject via `POST /api/mandate/escalatie/{id}/reject` with a reason
- THEN the escalation status SHALL become "afgewezen" with the reason stored in `toelichting`
- AND the decision SHALL NOT be executed and the case SHALL remain in its prior status
- AND the initiator SHALL be notified with the rejection reason

#### Scenario: Approval endpoint rejects an unauthorized approver

- GIVEN an open escalation routed to a specific mandaathouder
- WHEN a different authenticated user who does not hold the required mandate calls approve
- THEN the request SHALL be rejected and the decision SHALL NOT be executed

### Requirement: Escalation Rerouting on Personnel Change

The `MandaatEscalatieService` SHALL reroute open escalaties to the new role holder when a person
is replaced in a role.

#### Scenario: Open escalaties reroute to the new role holder

- GIVEN open escalaties with `escalatiePadEindigtBij` = "carol.dewit" in role "Hoofd VV"
- WHEN Carol's assignment ends and "frank.kerkhof" is assigned to "Hoofd VV"
- THEN `autoRerouteOnPersonnelChange` SHALL update those escalaties to `escalatiePadEindigtBij` = "frank.kerkhof"
- AND SHALL notify both Frank (now responsible) and Carol (no longer responsible)
