## ADDED Requirements

### Requirement: A rerun of the case-flow seed finds its case types by slug (REQ-SEED-010)

The case-flow seed SHALL look up a case type an earlier run stored by the
slug the seed gives it, in the object's metadata, and SHALL NOT create a
second case type for a slug that already exists. It SHALL fall back to the
title only for a seed row that carries no slug.

#### Scenario: a second repair run creates no second case type
@e2e exclude a repair step with no screen; covered in tests/Unit/Repair/CaseFlowSeedDataRepairStepTest.php::testACaseTypeStoredByAnEarlierRunIsFoundBySlug and tests/Unit/Repair/CaseFlowSeedIndexTest.php::testACaseTypeIsLookedUpBySlugInTheMetadata

- **GIVEN** an earlier run stored "Omgevingsvergunning kleine bouwactiviteit" with slug `omgevingsvergunning-kleinbouw`, its title kept as a language map
- **WHEN** the repair step runs again
- **THEN** it SHALL find that case type by its slug
- **AND** it SHALL NOT create a second one
