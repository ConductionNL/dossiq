# woo-publication-via-opencatalogi Delta: publication-category-from-decision-type

**Status**: draft
**Scope**: the information category a published decision is filed under.

## ADDED Requirements

### Requirement: A decision is filed under its decision type's publication category

A decision type MAY carry `publicationCategory` with a `code`, `label` and
`uri`. A published decision SHALL be filed under the category of its decision
type when that row carries all three, and under `infocat014`
(Woo-verzoeken en -besluiten) otherwise. A decision type row that does not
exist SHALL read as having no category. A read of the decision type that
fails SHALL fail the publish, as the publish's other reads do. A shipped
decision type row stored before the property existed SHALL get its shipped
category on upgrade, and a row that already carries one SHALL keep it.

#### Scenario: The Woo decision type names its category
- **GIVEN** a decision whose `decisionType` is the shipped Woo decision type `3c0f5a00-0000-4000-a000-00000000d001`, which carries `publicationCategory`
- **WHEN** `PublicationCategory::forDecision()` is called
- **THEN** it returns code `infocat014`, label "Woo-verzoeken en -besluiten" and the TOOI URI `https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532`

@e2e exclude Needs OpenCatalogi. Covered by `PublicationCategoryTest::testTheDecisionTypeRowNamesTheCategory` and `testTheShippedWooDecisionTypeCarriesItsCategory`.

#### Scenario: A decision type without a category
- **GIVEN** a decision whose `decisionType` is not a stored row, or whose row carries no complete `publicationCategory`
- **WHEN** `PublicationCategory::forDecision()` is called
- **THEN** it returns the `infocat014` entry, never null

@e2e exclude Needs OpenCatalogi. Covered by `PublicationCategoryTest::testEverythingElseFallsBack`.

#### Scenario: An existing instance keeps its category
- **GIVEN** an instance whose stored Woo decision type row predates `publicationCategory`
- **WHEN** the app is upgraded
- **THEN** the row carries the shipped category, and a row that already had one is not changed

@e2e exclude Repair step. Covered by `CopyShippedPublicationCategoriesTest`.
