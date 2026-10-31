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

### Requirement: An install with a seeded case type stored twice keeps one current copy

An upgrade SHALL keep the newest copy of a seeded case type current and SHALL point every older copy of the same seed
slug, version and organisation at it through `supersededBy`, so the new case picker offers one. It SHALL NOT delete a copy or move a
case, and SHALL leave versions of a case type, copies already superseded, and another organisation's copies alone.

#### Scenario: Omgevingsvergunning kleine bouwactiviteit stored twice

- **GIVEN** two copies with slug `omgevingsvergunning-kleinbouw`, version 1, stored at 14:08 and 23:34 on 10 October
- **WHEN** the repair runs
- **THEN** the 14:08 copy is superseded by the 23:34 copy, and its cases stay on it

