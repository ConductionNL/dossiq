# Tasks: seeded-case-types-start-their-terms

- [x] 1.1 Add `legalBasisSources` to the `deadlineDefinition` schema.
  - `tests/Unit/Repair/ShippedDeadlineDefinitionCoverageTest.php::testEveryShippedCitationNamesAnArticleAndAnOfficialSource`
- [x] 1.2 Add the nine definitions to `lib/Settings/termijnbewaking_seed_data.json`.
  - `tests/Unit/Repair/ShippedDeadlineDefinitionCoverageTest.php::testEachDecidedCaseTypeCarriesItsDecidedTerm`
- [x] 1.3 Sweep the register's case types by the resolver's key, with week durations, and hold the open questions to a named list.
  - `tests/Unit/Repair/ShippedDeadlineDefinitionCoverageTest.php::testEveryShippedCaseTypeWithADeadlineHasATermijnDefinitie`
  - `tests/Unit/Repair/ShippedDeadlineDefinitionCoverageTest.php::testTheOpenQuestionsAreStillOpen`
- [x] 2.1 `TermDefinitions::activeFor()` answers statutory definitions only.
  - `tests/Unit/Service/Termijn/TermDefinitionsKindTest.php`
- [x] 2.2 Bind the planned clock from a `kind: planned` definition when the case type declares no `plannedLeadTime`.
  - `tests/Unit/Service/CaseTermsServicePlannedDefinitionTest.php`
  - `tests/Unit/Listener/DeadlineCaseCreatedListenerPlannedTest.php`
- [ ] 3.1 Ruben: decide the term for `subsidieaanvraag` and `zaaktype-innovatiefonds-2026` (gemeentelijke ASV).
