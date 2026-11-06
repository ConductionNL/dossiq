## ADDED Requirements

### Requirement: The case page shows words, not raw values

The case page SHALL show the initiator type as a translated label, and SHALL NOT count days overdue on a case in its final status.

#### Scenario: A company initiator
- **GIVEN** a case with `initiatorType: company`
- **WHEN** a Dutch handler opens the case page
- **THEN** the initiator type SHALL read "Bedrijf"

#### Scenario: A closed case past its deadline
- **GIVEN** a case with `isFinalStatus: true` whose deadline passed 24 days ago
- **WHEN** a Dutch handler opens the case page
- **THEN** the deadline tile SHALL read "Afgehandeld" and SHALL NOT read "24 dagen te laat"

@e2e exclude Configuration over library widgets; tests/vitest/caseSettledAndInitiatorLabels.spec.js checks the declarations and the Dutch labels, and nextcloud-vue's own tests cover the rendering.
