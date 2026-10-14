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

## 3. Every exit records a reason (decided 14 October)

- [x] 3.1 D1: generic case fields `documentSearchOutcome`, `documentSearchNote`, `inadmissibilityReason`; a recorded "nothing found" with a note counts as assessed
  - Files: lib/Settings/register.d/89-search-outcome-and-admissibility.json, lib/Service/Assessment/DocumentSearchOutcome.php, lib/Service/Transitions/DocumentAssessmentGuard.php, l10n
  - Test: tests/Unit/Settings/WooFlowGuardsTest.php (`testARecordedNothingFoundCountsAsAssessed`)
- [x] 3.2 D2: a decision-only publication when the case records that nothing is disclosable
  - Spec ref: specs/woo-publication-via-opencatalogi/spec.md
  - Files: lib/Service/WooPublicationService.php
  - Test: tests/Unit/Service/WooPublicationServiceTest.php (five cases: all withheld, nothing found, nothing found without a note, a redaction still missing, an assessment outstanding)
- [x] 3.3 D3: "Niet ontvankelijk" from Beoordeling ontvankelijkheid to Afgehandeld, withheld until `inadmissibilityReason`; the withdrawal moves wait for `withdrawalReason`
  - Test: tests/Unit/Settings/WooFlowGuardsTest.php (`testAnInadmissibleRequestClosesWithItsReason`, `testTheWithdrawalStaysReachableAndWaitsForItsReason`, `testEveryExitWithoutADecisionWaitsForAReason`)

## 4. Existing cases

- [x] 4.1 Repair step `RebindCasesOffTheBasisFlow`, registered after `MigrateWorkflowDefinitions` in post-migration and install
  - Files: lib/Repair/RebindCasesOffTheBasisFlow.php, appinfo/info.xml
  - Test: tests/Unit/Repair/RebindCasesOffTheBasisFlowTest.php
