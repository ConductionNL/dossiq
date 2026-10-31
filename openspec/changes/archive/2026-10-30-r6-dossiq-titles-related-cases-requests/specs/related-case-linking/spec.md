# related-case-linking Delta: r6-dossiq-titles-related-cases-requests

## ADDED Requirements

### Requirement: The Related cases card lists only cases a handler can name

The Related cases card on a case SHALL list cases only: the typed case
relations, the parent case, and the planned follow-ups. It SHALL NOT list the
configuration objects a case refers to (its case type, status type, workflow
template) or any other object OpenRegister returns as used. A related case
whose title cannot be read SHALL be left out, never shown as its uuid. A click
on a related or parent case row SHALL open that case.

#### Scenario: A case with a case type, a status type and a workflow template
<!-- @e2e exclude Proven by tests/vitest/relatedCasesCard.spec.js (library source pin on showObjects) and live on :8099 (.lane-logs/r6dq-related-after.png). -->
- **GIVEN** a case that refers to a case type, a status type and a workflow template and has no related cases
- **WHEN** a handler opens its Related tab
- **THEN** the Related cases card lists none of those objects and no uuid

#### Scenario: A legacy relation
<!-- @e2e exclude Proven by CaseRelationServiceTest::testALegacyRelationCarriesTheFarCaseTitle and tests/vitest/relatedCasesCard.spec.js. -->
- **GIVEN** a case whose stored relation names only the far case's uuid
- **WHEN** the card reads `/api/cases/{id}/relations`
- **THEN** the row carries the far case's title, and is left out when that case cannot be read

#### Scenario: The parent case
<!-- @e2e exclude Proven by tests/vitest/relatedCasesCard.spec.js. -->
- **GIVEN** a sub-case whose parent case is titled "Omgevingsvergunning Kerkstraat"
- **WHEN** a handler opens its Related tab
- **THEN** the card shows "Parent case" with that title, and a click on it opens the parent
