## ADDED Requirements

### Requirement: A case type declares which roles keep a field and which lose it (REQ-SEC-FR-1)

A case type SHALL declare `fieldRoleRules` beside its rights matrix. A rule
SHALL name a `field`, a `rule` of `hidden` or `readOnly`, the groups it
`groups` (the roles that lose the field) and the groups that `heldBy` (the
roles that keep it), and MAY carry a `reason`. The two lists SHALL NOT be
derived from one another: the lifecycle reads a deny list and a property
authorization block reads an allow list, and the set of groups on the server
is not something a case type can know. A group named in both lists SHALL lose
the field.

No dossiq code SHALL filter these fields.

#### Scenario: A handler does not receive the field the rule hides
@e2e tests/e2e/field-rules.spec.ts

- **GIVEN** a case type hiding `qualityScore` from `behandelaars` and holding it for `dossiq-quality`
- **WHEN** a member of each group reads the same case
- **THEN** the member of `dossiq-quality` SHALL receive `qualityScore`
- **AND** the member of `behandelaars` SHALL NOT receive it

#### Scenario: A handler is refused the change the rule freezes
@e2e tests/e2e/field-rules.spec.ts

- **GIVEN** the same case type marking `confidentiality` read only for `behandelaars`
- **WHEN** a member of `behandelaars` changes it
- **THEN** the save SHALL be refused, naming the field
- **AND** a member of `dossiq-quality` SHALL still be able to change it

#### Scenario: A rule restricting nobody is not published
- **GIVEN** a rule whose `groups` list normalises to empty
- **WHEN** the case type is published
- **THEN** no lifecycle entry SHALL be written for it
- @e2e exclude {asserted in tests/Unit/Service/Access/FieldRoleRuleDeclarationTest.php::testARuleRestrictingNobodyPublishesNoLifecycleEntry; an entry with no groups applies to everyone including administrators}

#### Scenario: A rule naming no holder is not published
- **GIVEN** a rule whose `heldBy` list normalises to empty
- **WHEN** the case type is published
- **THEN** no property authorization block SHALL be written for it
- @e2e exclude {asserted in tests/Unit/Service/Access/FieldRoleRuleDeclarationTest.php::testARuleNamingNoHolderPublishesNoPropertyBlock; an empty allow list strips the field for every non-administrator}

### Requirement: The declaration is projected onto the case schema at publish (REQ-SEC-FR-2)

Publishing a case type SHALL write its role rules onto the live `case` schema:
into `x-openregister-lifecycle.states.<statusTypeUuid>.fields` for every state
the type declares, and onto `properties.<field>.authorization` for the read and
the write. Both writes SHALL merge and SHALL NOT replace: another case type's
rules, and the authorization the register JSON declares, SHALL survive. A rule
the case type no longer declares SHALL be removed, and a property left with no
grants SHALL lose its `authorization` key rather than keep an empty one.

The property block SHALL be re-applied after the register import, which
rewrites a schema's properties.

#### Scenario: Another case type's grants survive a publish
- **GIVEN** two case types declaring rules on the same case schema
- **WHEN** one of them is published
- **THEN** the other's grants SHALL still stand
- @e2e exclude {asserted in tests/Unit/Service/Access/CaseFieldRoleProjectorTest.php::testAnotherCaseTypesGrantsSurviveAPublish}

#### Scenario: The register's own grant survives a withdrawal
- **GIVEN** `riskAssessment` restricted by the register JSON and a case type withdrawing its own rule
- **WHEN** the case type is published
- **THEN** the register's grant SHALL still stand
- @e2e exclude {asserted in tests/Unit/Service/Access/CaseFieldRoleProjectorTest.php::testTheRegistersOwnGrantSurvivesAWithdrawal}

#### Scenario: A role rule applies in every state the type declares
- **GIVEN** a case type with two statuses and one role rule
- **WHEN** it is published
- **THEN** both states SHALL carry the rule
- @e2e exclude {asserted in tests/Unit/Service/Status/CaseStateFieldRuleProjectorTest.php::testARoleRuleLandsInEveryState; a rule carried by one state only lets a handler read the field by moving the case along}

### Requirement: The access tab names the rule behind a field that is not there (REQ-SEC-FR-3)

The access tab SHALL list the case type's role rules, each with the field, what
the rule does, the groups it affects, the groups that keep the field and the
reason its author wrote. A row SHALL be marked as applying to the reader only
when the case's `@self.fieldRules` names that field under that rule. The tab
SHALL NOT work out for itself whether a rule applies.

#### Scenario: The tab names the rule behind the gap
@e2e tests/e2e/field-rules.spec.ts

- **GIVEN** a handler reading a case whose type hides `qualityScore` from them
- **WHEN** they open the access tab
- **THEN** the tab SHALL name the field, the group that loses it and the group that keeps it

#### Scenario: An instance that answered nothing is not marked as applying
- **GIVEN** a case carrying no `@self.fieldRules` at all
- **WHEN** the rows are built
- **THEN** no row SHALL be marked as applying to the reader
- @e2e exclude {asserted in tests/vitest/fieldRoleRules.spec.js; an older OpenRegister answers nothing, and inventing "this does not apply to you" is the one thing the panel exists to report}
