# Tasks — Member 02: Authorization Engine (code)

Sourced from giant tasks 3–4 (MandaatCheckService; ABAC Policy Engine Integration).

## 1. MandaatCheckService

- [ ] Implement `MandaatCheckService.isAuthorized(userId, decisionType, caseId)`
- [ ] Implement `getApplicableMandaten(decisionType, caseType, date)` — query by decisionType and validity window
- [ ] Implement `resolveUserRole(userId, date)` — active MedewerkerRolToewijzing, primair vs waarnemer flags
- [ ] Check if resolved role holds an applicable mandaat; if not → `{authorized: false, reden: "niet_bevoegd"}`
- [ ] Implement `evaluateConditions(mandaat, caseProperties)` — parse voorwaarden, match case properties, return `{passed, failedConditions}`
- [ ] Return `plafond_overschreden` / `subdelegatie_niet_toegestaan` reden on condition failure
- [ ] Return `{authorized: true, mandaatId}` on success
- [ ] Unit-cover role-holds, role-doesn't, plafond-exceeded, subdelegatie-blocked, waarnemer paths

## 2. AbacPolicyService

- [ ] Create `AbacPolicyService` wrapper around the OpenRegister policy engine
- [ ] Implement `evaluatePolicy(policyName, factSet)` → `{allowed, violations[]}`
- [ ] Integrate: MandaatCheckService delegates condition evaluation to the policy engine
- [ ] Pass fact set `{userId, rolId, mandaatId, caseType, caseProperties, decisionType}`
- [ ] Test policy evaluation with sample plafond + subdelegatie policies
