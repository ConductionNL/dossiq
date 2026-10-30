# frontend-locale-completeness delta: case-page-dutch-labels-one-history-tour-first

## ADDED Requirements

### Requirement: Field override labels and the term tile are translated (REQ-FLC-012)

A field override's `label` and a countdown tile's `label` SHALL pass through the
app's translation before they reach the renderer, so a field the manifest
relabels reads in the reader's language on the create form, the edit form and
the case panels.

#### Scenario: The requester field reads Aanvrager
@e2e exclude {the translation pass runs on the built manifest before render; asserted for both structure profiles in tests/vitest/casePageLabelsHistoryAndTourFirst.spec.js and checked live with a screenshot}

- **GIVEN** a reader whose language is Dutch
- **WHEN** they open the new case form, the case edit form or the case's Overview tab
- **THEN** the requester field SHALL be labelled "Aanvrager"

#### Scenario: The term card has one Dutch heading
@e2e exclude {asserted on the translated manifest in tests/vitest/casePageLabelsHistoryAndTourFirst.spec.js and checked live with a screenshot}

- **GIVEN** a reader whose language is Dutch, in the simple structure
- **WHEN** they open a case
- **THEN** the term card SHALL be headed "Termijn" once, and no "Deadline" SHALL show

### Requirement: A refused lifecycle act is told in the reader's language (REQ-FLC-013)

The sentence the server gives for a refused finish, abort or archive SHALL be
translated on the server, so the lifecycle dialog shows it in the reader's
language.

#### Scenario: An act that needs an administrator
@e2e exclude {needs a non-admin user on a case type without a declared group; the translation is asserted in tests/Unit/Service/CaseLifecycleGateTest.php}

- **GIVEN** a reader whose language is Dutch who is not an administrator
- **WHEN** they open the lifecycle dialog on a case whose type declares no group for archiving
- **THEN** the refusal SHALL read "Voor deze handeling is een beheerder nodig."
