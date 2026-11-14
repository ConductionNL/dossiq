## ADDED Requirements

### Requirement: A case type can carry a short name

A case type SHALL be able to carry `shortName`, a text of at most 30 characters, and an administrator SHALL be
able to set it on the create form and the case type page. The shipped Woo request types SHALL set "Woo". The week
strip on Mijn werk SHALL show each deadline's case number followed by its case type's short name, or its title
when the short name is empty.

#### Scenario: A Woo request in the week strip
- **GIVEN** Pieter's case 2026-4837 of the type Woo-verzoek (short name "Woo"), due this week
- **WHEN** he opens Mijn werk
- **THEN** its item in "Termijnen deze week" reads "2026-4837 · Woo"
- @e2e exclude the strip's text comes from nextcloud-vue's week strip, unit-tested there; the configuration is pinned by tests/vitest/weekStripCaseTypeShortName.spec.js

#### Scenario: A case type without a short name
- **GIVEN** case 2026-4836 of the type Bezwaar, which has no short name
- **WHEN** Pieter opens Mijn werk
- **THEN** its item reads "2026-4836 · Bezwaar"
- @e2e exclude as above
