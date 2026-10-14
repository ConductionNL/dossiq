# Tasks: woo-stages-are-guarded-by-their-flow

## 1. Generic guard

- [x] 1.1 `DocumentAssessmentGuard` (`require: gathered | assessed`, fails closed), registered in `GuardRegistry` as `documentAssessment`
  - Spec ref: specs/status-transition-engine/spec.md, "A move MAY require the case's documents gathered or assessed"
  - Files: lib/Service/Transitions/DocumentAssessmentGuard.php, lib/Service/Transitions/GuardRegistry.php
  - Test: tests/Unit/Settings/WooFlowGuardsTest.php; tests/Unit/Settings/WorkflowGuardConformanceTest.php sweeps the shipped guards through the real registry

## 2. The Woo flow

- [x] 2.1 Package `woo-verzoek` (install auto, flows only) with the guarded flow; the case type names it as `workflowDefinition`
  - Spec ref: specs/status-transition-engine/spec.md, "The Woo request case type MUST ship a guarded flow"
  - Files: lib/Settings/packages/woo-verzoek/package.json, lib/Settings/packages/woo-verzoek/flows/woo-verzoek.json, lib/Settings/register.d/81-woo-verzoek.json
  - Test: tests/Unit/Settings/WooFlowGuardsTest.php through the real `OfferedTransitions`, `TransitionDeclarations` and `TransitionPreconditions`
- [x] 2.2 The flow sweeps accept a package flow for a case type register.d seeds
  - Files: tests/Unit/Settings/ShippedPackageCaseTypes.php, tests/Unit/Settings/PackageDraftFlowsTest.php, tests/Unit/Settings/PackageFlowStatusReferencesTest.php

## 3. Open

- [ ] 3.1 D1, D2, D3 in the proposal: decide, then add the moves
- [ ] 3.2 Rebind or repair open Woo cases bound to the backfilled basis flow (instance owner)
