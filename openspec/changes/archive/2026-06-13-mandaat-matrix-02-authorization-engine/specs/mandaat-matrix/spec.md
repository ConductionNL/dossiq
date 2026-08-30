# mandaat-matrix Specification — Member 02: Authorization Engine

---
status: proposed
---

## Purpose

Implement the real-time bevoegdheidscheck: resolve a user's current role (including waarnemer),
determine whether that role holds the applicable mandate, and evaluate conditions (plafond,
subdelegatie) via the ABAC policy engine.

## ADDED Requirements

### Requirement: Real-Time Authorization Verdict

The `MandaatCheckService` SHALL provide `isAuthorized(userId, decisionType, caseId)` returning a
verdict `{authorized, mandaatId?, reden?}`, resolving the user's current role and evaluating the
applicable mandate's conditions.

#### Scenario: Authorized when role holds mandate and conditions pass

- GIVEN a zaak of zaaktype "Omgevingsvergunning" with bouwsom €75.000
- AND a user with role "Senior Vergunningverlener" which holds mandate M.3.1.2 (plafond €100.000)
- WHEN `isAuthorized(user, "vergunning_verlenen", zaakId)` is called
- THEN the service SHALL resolve the user's role as of today
- AND SHALL find that the role holds M.3.1.2
- AND SHALL evaluate bouwsom €75.000 ≤ plafond €100.000 as passing
- AND SHALL return `{authorized: true, mandaatId: <M.3.1.2 uuid>, reden: null}`

#### Scenario: Denied when role does not hold the mandate

- GIVEN a user with role "Medewerker Vergunningen" which does NOT hold mandate M.3.1.2
- WHEN `isAuthorized(user, "vergunning_verlenen", zaakId)` is called for a decision requiring M.3.1.2
- THEN the service SHALL return `{authorized: false, mandaatId: null, reden: "niet_bevoegd"}`

#### Scenario: Denied when plafond is exceeded

- GIVEN a user with role "Vergunningverlener" holding M.3.1.1 (plafond €50.000)
- AND a zaak with bouwsom €250.000
- WHEN `isAuthorized(user, "vergunning_verlenen", zaakId)` is called
- THEN the service SHALL return `{authorized: false, reden: "plafond_overschreden"}`

### Requirement: Waarnemer Role Resolution

The `MandaatCheckService` SHALL resolve waarnemer (substitute) assignments active on the decision
date, granting the substitute the authority of the role they are covering when subdelegation rules
permit.

#### Scenario: Waarnemer authorized during active coverage period

- GIVEN a MedewerkerRolToewijzing where Hoofd Stadsbeheer is waarnemer for role "Hoofd VTH" from
  2026-06-15 to 2026-06-30
- WHEN on 2026-06-22 Hoofd Stadsbeheer attempts a decision requiring a mandate held by "Hoofd VTH"
- THEN the service SHALL resolve the active waarnemer assignment
- AND SHALL return `{authorized: true}` with a role snapshot flagged `toewijzingType: "waarnemer"`

### Requirement: Subdelegatie Enforcement

The `MandaatCheckService` SHALL deny authority obtained via waarnemer when the mandate forbids
subdelegation.

#### Scenario: Subdelegation blocked

- GIVEN mandate M.4.2.1 with `subdelegatieToegestaan: false` held by role "Wethouder RO"
- AND Beleidsmedewerker RO is a waarnemer for Wethouder RO, valid today
- WHEN Beleidsmedewerker RO attempts a decision requiring M.4.2.1
- THEN the service SHALL return `{authorized: false, reden: "subdelegatie_niet_toegestaan"}`

### Requirement: ABAC Policy Engine Delegation

The `AbacPolicyService` SHALL wrap the OpenRegister policy engine, accepting a fact set and
returning `{allowed, violations[]}`, and `MandaatCheckService` SHALL use it for condition
evaluation.

#### Scenario: Conditions evaluated by the policy engine

- GIVEN a mandate with `voorwaarden` containing `plafond_bedrag` and `subdelegatie_toegestaan`
- WHEN `MandaatCheckService` evaluates conditions for a case
- THEN it SHALL call `AbacPolicyService.evaluatePolicy(policyName, factSet)` with the fact set
  `{userId, rolId, mandaatId, caseType, caseProperties, decisionType}`
- AND SHALL interpret a non-empty `violations[]` as a failed condition with the corresponding `reden`
