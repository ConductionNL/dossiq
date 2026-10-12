# termijnbewaking-schemas delta: seeded-case-types-start-their-terms

## ADDED Requirements

### Requirement: Every shipped case type with a processing deadline has a cited term or a named open question (REQ-TERM-SCHEMA-004)

The app SHALL ship, for every case type in its seeds and its register that declares a
`processingDeadline`, a TermijnDefinitie bound by the key `CaseTypeSlugResolver`
answers for that case type (its `identifier`, else its slug), whose duration equals the
declared deadline. A case type whose term depends on a gemeente's own regulation and
cannot be stated generically SHALL instead be named in the coverage test's list of open
questions, and SHALL have no definition while it is listed.

#### Scenario: The register's case types are swept by the resolver's key
@e2e exclude the binding is seed data read by key; proven by tests/Unit/Repair/ShippedDeadlineDefinitionCoverageTest.php::testEveryShippedCaseTypeWithADeadlineHasATermijnDefinitie

- **GIVEN** the shipped seeds and register
- **WHEN** a case type declares a `processingDeadline` in days or weeks
- **THEN** a shipped definition SHALL bind to its identifier, or its slug when it has no identifier, with the same number of days
- **OR** the case type SHALL be named as an open question and have no definition

#### Scenario: The decided terms are shipped as decided
@e2e exclude seed data; proven by tests/Unit/Repair/ShippedDeadlineDefinitionCoverageTest.php::testEachDecidedCaseTypeCarriesItsDecidedTerm

- **GIVEN** the shipped seeds
- **THEN** `klacht-behandeling` SHALL carry a statutory term of 42 calendar days with one extension of at most 28 days, citing Awb art. 9:11
- **AND** `evenementenvergunning-2024` and `evenementenvergunning` SHALL carry a statutory term of 56 calendar days with one extension of at most 56 days, citing model-APV art. 1:2 and Awb art. 4:13
- **AND** `zaaktype-cultuur-subsidie-2026`, `subsidieaanvraag` and `zaaktype-innovatiefonds-2026` SHALL each carry a statutory term of 56 calendar days citing Awb art. 4:13 and art. 4:14 lid 3, with no fixed extension, and each case type SHALL declare that same deadline
- **AND** `melding-openbare-ruimte`, `building-permit`, `grant-application`, `citizen-complaint` and `foi-request` SHALL carry a `planned` service norm equal to their processing deadline and no statutory term

### Requirement: A term definition cites its sources (REQ-TERM-SCHEMA-005)

The `deadlineDefinition` schema SHALL declare `legalBasisSources`, a list of citations
each naming an article and the URL of the text it is read from. Every shipped statutory
definition this change adds SHALL carry at least one, and each URL SHALL point at an
official publication.

#### Scenario: A statutory definition names where its rule is written
@e2e exclude seed data; proven by tests/Unit/Repair/ShippedDeadlineDefinitionCoverageTest.php::testEveryShippedCitationNamesAnArticleAndAnOfficialSource

- **GIVEN** a shipped statutory definition added by this change
- **THEN** it SHALL list at least one citation with a non-empty article and an https URL on wetten.overheid.nl or vng.nl
