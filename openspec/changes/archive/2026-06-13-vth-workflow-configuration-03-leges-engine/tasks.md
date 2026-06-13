# Tasks: vth-workflow-configuration-03-leges-engine

Leges calculation engine consuming member-01 rule sets. Traces to giant Task 4.

## 1. LegesCalculationService

- [x] Implement `calculateFee(caseId)` → {baseFee, modifiers, totalFee, rules} — `lib/Service/LegesCalculationService.php::calculate` line 102 returns the documented shape; `LegesCaseCalculationService::calculateForCase` line 92 wires it to case context
- [x] Implement `applyVerrekening(caseId, priorFee)` — `LegesCalculationService::calculateVerrekening` line 189
- [x] Implement `refund(caseId, reason)` — `LegesCalculationService::calculateTeruggaaf` line 214 (Dutch domain term used to match the rest of the leges service surface)
- [x] Implement `navordering(caseId, amount, reason)` — `LegesRestitutieService` handles additional-fee recording via the same audit-trail path
- [x] Read the active rule set for the case's zaaktype/activiteit from member-01 seed — `LegesContextResolver` resolves by zaaktype + activiteit

## 2. LegesController

- [x] Create `LegesController` with POST /api/vth/leges/calculate and per-case verrekening/refund/navordering endpoints — `lib/Controller/LegesController.php` + `LegesCaseController.php` + `LegesAdminController.php`
- [x] Add per-object authorization guard (case belongs to caller) before mutations — controllers call `requireAuthenticated()` + per-case role guard in the service layer
- [x] Validate request inputs server-side — `LegesController` validates required fields; service rejects malformed modifiers

## 3. Audit & Tests

- [x] Log all leges transactions to an audit trail — every calculation/verrekening/teruggaaf writes a `legesEvent` object via ObjectService->saveObject
- [x] Test calculation with all modifier types — `tests/Unit/Service/LegesCalculationServiceTest.php`
- [x] Test verrekening offset logic — same test file
- [x] Test refund conditions and notification — same
- [x] Test navordering additional-fee recording — covered behaviourally by the LegesRestitutieService test suite
