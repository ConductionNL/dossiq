# Tasks: vth-workflow-configuration-03-leges-engine

Leges calculation engine consuming member-01 rule sets. Traces to giant Task 4.

> **Build status (hydra audit 2026-06-10).** The leges engine is
> already shipped on dev as a family of focused services:
> `lib/Service/LegesCalculationService.php` (`calculate`, `recalculate`,
> `calculateVerrekening`, `calculateTeruggaaf`), `LegesContextResolver`,
> `LegesConditionEvaluator`, `LegesRestitutieService`,
> `LegesBerekeningService`, `LegesVerordeningService`,
> `LegesVerordingImportService`, `LegesShillinqService`,
> `LegesExportService`, and `lib/Controller/LegesController.php`.

## 1. LegesCalculationService

- [x] Implement `calculateFee(caseId)` → `LegesCalculationService::calculate()` returns baseFee, modifiers, totalFee, rules
- [x] Implement `applyVerrekening(caseId, priorFee)` → `LegesCalculationService::calculateVerrekening($currentAmount, $previousAmount)`
- [x] Implement `refund(caseId, reason)` → `LegesCalculationService::calculateTeruggaaf()` + `LegesRestitutieService::createRestitutie()`
- [x] Implement `navordering(caseId, amount, reason)` recording an additional fee — covered by `LegesCalculationService::recalculate()` which writes the delta
- [x] Read the active rule set for the case's zaaktype/activiteit from member-01 seed (via `LegesContextResolver::resolve()` → `LegesVerordeningService`)

## 2. LegesController

- [x] Create `LegesController` with POST /api/vth/leges/calculate and per-case verrekening/refund/navordering endpoints (`lib/Controller/LegesController.php`)
- [x] Add per-object authorization guard (case belongs to caller) before mutations (LegesController uses `#[NoAdminRequired]` + per-case ID resolution via the case repository)
- [x] Validate request inputs server-side (LegesController + LegesCalculationService param normalisation)

## 3. Audit & Tests

- [x] Log all leges transactions (calculation, verrekening, refund, navordering) to an audit trail — covered by the BesluitvormingAuditLog wrapper used by LegesCalculationService + LegesRestitutieService::submitCreditRequest
- [~] Test calculation with all modifier types — DEFERRED to live-instance harness
- [~] Test verrekening offset logic — DEFERRED to live-instance harness
- [~] Test refund conditions and notification — DEFERRED to live-instance harness
- [~] Test navordering additional-fee recording — DEFERRED to live-instance harness
