## ADDED Requirements

### Requirement: A case shows who holds which right, and where the grant came from (REQ-CGP-01)

A case SHALL be able to show who holds which right on it and the source of
each grant, read from openregister's provenance. dossiq SHALL NOT compute
an effective permission, SHALL NOT store a copy of a grant, and SHALL NOT
summarise the provenance into a value of its own.

#### Scenario: an auditor asks who could open this dossier
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a case whose grants come from a role, a group and a share
- **WHEN** an authorised reader opens its access panel
- **THEN** each holder SHALL be listed with the source of their grant

#### Scenario: dossiq computes nothing
@e2e exclude a structural scan of lib/, not a journey: the class whose existence it forbids has no page to open. tests/Unit/Architecture/NoSecondPermissionEvaluatorTest.php

- **GIVEN** the dossiq tree
- **WHEN** it is read for an effective-permission evaluator
- **THEN** none SHALL exist

### Requirement: A refusal names the rule that refused (REQ-CGP-02)

When an act on a case is refused, dossiq SHALL show the rule that refused
it, and SHALL NOT show only a status code. `CaseActionProvider` SHALL keep
answering which actions the calling user may take, and SHALL read
openregister's effective grants as well as its own guards.

#### Scenario: a handler is told why, not just no
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a handler without the right to close a case
- **WHEN** they try to close it
- **THEN** the refusal SHALL name the rule that refused

#### Scenario: the action list still answers up front
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a handler opening a case
- **WHEN** the case is returned
- **THEN** the actions they may take SHALL be returned with it
- **AND** an action they may not take SHALL NOT be offered

### Requirement: Case-type rights are declared per department, role and confidentiality (REQ-CGP-03)

A case type SHALL declare its rights as a matrix of department by role,
separately per confidentiality level. Confidentiality SHALL be a dimension
of the declaration and SHALL NOT be folded into a role name.

#### Scenario: a department may see the ordinary cases and not the confidential ones
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a case type granting a department read at the ordinary level only
- **WHEN** a member of that department lists cases of that type
- **THEN** the confidential ones SHALL NOT be listed

#### Scenario: no role name carries a confidentiality level
@e2e exclude the declared role vocabulary is read from the shipped register definitions, which no browser reaches. tests/Unit/Settings/CaseTypeRightsMatrixTest.php

- **GIVEN** the declared roles
- **WHEN** their names are read
- **THEN** none SHALL encode a confidentiality level

### Requirement: A group of case types is granted once (REQ-CGP-04)

Case types SHALL be groupable, and a right SHALL be grantable on the group
so that every type in it inherits the grant. Inheritance SHALL be
openregister's, and dossiq SHALL NOT re-apply a group grant to each type.

#### Scenario: a samenwerkingsverband grants once
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a group holding thirty case types and one grant on the group
- **WHEN** a member opens a case of any of them
- **THEN** the grant SHALL apply

#### Scenario: adding a case type to the group needs no second grant
@e2e tests/e2e/case-grants-name-their-source.spec.ts

- **GIVEN** a granted group
- **WHEN** a new case type joins it
- **THEN** the grant SHALL apply to the new type without a further act

### Requirement: Every row of the access panel names the rule behind it (REQ-CGP-05)

The access panel SHALL read the permission set openregister publishes for
the case itself, and SHALL show each holder with the verbs they hold and
the rule behind each one: the level the rule is written at, the role it
arrived through, and whether the verb is in the published catalogue.

A verb the catalogue does not publish SHALL be shown as undeclared rather
than dropped. dossiq SHALL NOT subtract a deny from a grant, and SHALL NOT
fill in a rule openregister did not report.

A caller who may read the case but may not review its access SHALL be told
so, and that refusal SHALL NOT be rendered as a case with no rules on it.

#### Scenario: an auditor sees the rule, not only the holder
@e2e tests/e2e/case-grants-history-and-scope.spec.ts

- **GIVEN** a case whose read is granted through a role written at the schema level
- **WHEN** an authorised reader opens its access panel
- **THEN** the row SHALL name the role and the level the rule is written at

#### Scenario: a verb outside the catalogue is shown as undeclared
@e2e exclude the catalogue is read by the shaping function, and an undeclared verb needs a register that ships one. tests/vitest/caseAccessPanel.spec.js

- **GIVEN** a grant naming a verb the catalogue does not publish
- **WHEN** the panel lists it
- **THEN** the row SHALL say the verb is not declared

#### Scenario: a reader without the right to review access is told so
@e2e tests/e2e/case-grants-history-and-scope.spec.ts

- **GIVEN** a reader who may open the case but holds no manage right on it
- **WHEN** they open its access panel
- **THEN** the panel SHALL say they may not review access here
- **AND** the panel SHALL NOT report that no rule holds on the case

### Requirement: The panel answers who held a right on a past date (REQ-CGP-06)

A reader SHALL be able to ask the access panel for the set as it stood at a
named moment, read from openregister's history of the object. The answer
SHALL name who set the grant and which change took it away afterwards.

A moment openregister cannot answer for SHALL be reported as unanswered.
It SHALL NOT be shown as a moment at which nobody held anything.

#### Scenario: an auditor asks who could open this dossier in March
@e2e tests/e2e/case-grants-history-and-scope.spec.ts

- **GIVEN** a case whose grants changed since March
- **WHEN** a reader asks the panel for the set as it stood in March
- **THEN** the holders of that date SHALL be listed with who set them

#### Scenario: a date the trail does not reach says so
@e2e exclude a trail shorter than the question needs a register seeded months back, which no run has. tests/vitest/caseAccessPanel.spec.js

- **GIVEN** a case whose audit trail starts after the date asked about
- **WHEN** the panel reports that date
- **THEN** it SHALL say the set is unanswered for that moment

### Requirement: A grant that ends, or that reaches one area, says so (REQ-CGP-07)

A grant carrying an end SHALL be shown with the moment it ends, and a
grant confined to named registers or schemas SHALL be shown with the area
it reaches. dossiq SHALL read both from the rule openregister reported and
SHALL NOT decide whether such a grant still answers.

#### Scenario: a grant that runs out names its last day
@e2e tests/e2e/case-grants-history-and-scope.spec.ts

- **GIVEN** a grant written to end on a named date
- **WHEN** the panel lists it
- **THEN** the row SHALL name that date

#### Scenario: a delegated administrator's area is on the row
@e2e exclude the area is a property of the rule the panel renders, asserted on the shaping function. tests/vitest/caseAccessPanel.spec.js

- **GIVEN** a manage grant confined to one register
- **WHEN** the panel lists it
- **THEN** the row SHALL name that register

### Requirement: A case type may say when a right it grants ends (REQ-CGP-08)

A row of a case type's rights matrix MAY declare when the grant it
produces ends, written in the key and the format openregister reads, so
that no translation stands between the declaration and the rule.

The declaration SHALL be optional, and a row without it SHALL grant
without an end.

#### Scenario: a waarnemer is granted until the holiday ends
@e2e exclude the declared vocabulary is read from the shipped register definitions, which no browser reaches. tests/Unit/Settings/CaseTypeRightsMatrixTest.php

- **GIVEN** a rights row declaring the date its grant ends
- **WHEN** the case type is saved
- **THEN** the declaration SHALL be kept in openregister's own spelling

#### Scenario: a row without an end grants without one
@e2e exclude the same declaration read, with nothing on screen to open. tests/Unit/Settings/CaseTypeRightsMatrixTest.php

- **GIVEN** a rights row that declares no end
- **WHEN** the case type is saved
- **THEN** the row SHALL remain valid
