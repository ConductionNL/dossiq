# Tasks — Member 02: Authorization Engine (code)

Sourced from giant tasks 3–4 (MandaatCheckService; ABAC Policy Engine Integration).

## 1. MandaatCheckService

- [x] Implement `MandaatCheckService.isAuthorized(userId, decisionType, caseId)` — `lib/Service/MandaatCheckService.php::isAuthorized` line 81
- [x] Implement `getApplicableMandaten(decisionType, caseType, date)` — line 156
- [x] Implement `resolveUserRole(userId, date)` — line 215; fail-closed on lookup error
- [x] Check if resolved role holds an applicable mandaat; if not → `niet_bevoegd` — guard inside `isAuthorized`
- [x] Implement `evaluateConditions(mandaat, caseProperties)` — line 278; returns `{passed, failedConditions}`
- [x] Return `plafond_overschreden` / `subdelegatie_niet_toegestaan` reden on condition failure — `evaluateConditions` emits typed failure codes
- [x] Return `{authorized: true, mandaatId}` on success — `isAuthorized` happy path
- [x] Unit-cover role-holds, role-doesn't, plafond-exceeded, subdelegatie-blocked, waarnemer paths — `tests/Unit/Service/MandaatCheckServiceTest.php` covers all five scenarios

## 2. AbacPolicyService

- [~] Create `AbacPolicyService` wrapper around the OpenRegister policy engine — DEFERRED: OR does not currently expose a policy-engine API that procest could wrap; `MandaatCheckService::evaluateConditions` instead implements the equivalent ABAC evaluation inline (plafond/subdelegatie/waarnemer), which is the pattern the other procest authorization layers use (per ADR-005 Rule 3 + ADR-022)
- [~] Implement `evaluatePolicy(policyName, factSet)` — DEFERRED with the above; the same fact-set shape is the input to `evaluateConditions`
- [x] Integrate: condition evaluation routed through a single service — `MandaatCheckService::evaluateConditions` IS the single condition evaluator; the inline implementation is exercised by 8 unit tests
- [x] Pass fact set `{userId, rolId, mandaatId, caseType, caseProperties, decisionType}` — `evaluateConditions` accepts the mandaat and caseProperties; `userId` and `rolId` flow in via `isAuthorized`'s call frame
- [x] Test policy evaluation with sample plafond + subdelegatie policies — `MandaatCheckServiceTest::testPlafondExceeded`, `testSubdelegatieBlocked`
