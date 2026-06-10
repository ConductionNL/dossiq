# Tasks — Member 02: Authorization Engine (code)

Sourced from giant tasks 3–4 (MandaatCheckService; ABAC Policy Engine Integration).

## 1. MandaatCheckService

- [~] Implement `MandaatCheckService.isAuthorized(userId, decisionType, caseId)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `getApplicableMandaten(decisionType, caseType, date)` — query by decisionType and validity window — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `resolveUserRole(userId, date)` — active MedewerkerRolToewijzing, primair vs waarnemer flags — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Check if resolved role holds an applicable mandaat; if not → `{authorized: false, reden: "niet_bevoegd"}` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `evaluateConditions(mandaat, caseProperties)` — parse voorwaarden, match case properties, return `{passed, failedConditions}` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Return `plafond_overschreden` / `subdelegatie_niet_toegestaan` reden on condition failure — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Return `{authorized: true, mandaatId}` on success — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit-cover role-holds, role-doesn't, plafond-exceeded, subdelegatie-blocked, waarnemer paths — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. AbacPolicyService

- [~] Create `AbacPolicyService` wrapper around the OpenRegister policy engine — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `evaluatePolicy(policyName, factSet)` → `{allowed, violations[]}` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integrate: MandaatCheckService delegates condition evaluation to the policy engine — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Pass fact set `{userId, rolId, mandaatId, caseType, caseProperties, decisionType}` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test policy evaluation with sample plafond + subdelegatie policies — deferred to downstream cycle / fleet-wide adoption (handoff)
