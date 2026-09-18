## ADDED Requirements

### Requirement: A case type declares which AI features are on, and where they appear (REQ-AIC-01)

A case type SHALL declare, per AI feature, the surface on which that feature
appears: `case` for the case detail page, `intake` for the create form, or `none`.
A feature that is not declared SHALL render nothing and SHALL make no call.

The declaration SHALL name features by the slug hermiq registers them under, so
one feature is one thing across the two apps.

#### Scenario: A feature switched on for one case type is off for another
@e2e tests/e2e/ai-features-on-the-case.spec.ts

- **GIVEN** a case type declaring summarising on the case surface and a second case
  type declaring nothing
- **WHEN** a handler opens a case of each
- **THEN** the first SHALL offer summarising and the second SHALL offer nothing

#### Scenario: An undeclared feature makes no call
@e2e exclude The assertion is the absence of an outbound request, which is asserted in PHPUnit against the gateway rather than in a browser.

- **GIVEN** a case type declaring no AI features
- **WHEN** a case of that type is opened
- **THEN** no request SHALL be made to hermiq for that case

#### Scenario: A feature declared for intake does not appear on the case
@e2e tests/e2e/ai-features-on-the-case.spec.ts

- **GIVEN** a case type declaring a feature on the intake surface only
- **WHEN** a handler opens a case of that type
- **THEN** that feature SHALL NOT be offered on the case detail page

### Requirement: The provider and the place are read from hermiq, never set in dossiq (REQ-AIC-02)

For each declared feature, dossiq SHALL show which provider it will use and where
that provider runs, read from hermiq's AI feature register. dossiq SHALL NOT hold
a provider, model or residency setting of its own, and SHALL offer no way to
change one.

A second place to answer "which model saw this case, and in which jurisdiction" is
a second answer, and a functionaris gegevensbescherming given two has none.

#### Scenario: The case type screen shows where each feature runs
@e2e tests/e2e/ai-features-on-the-case.spec.ts

- **GIVEN** a feature bound in hermiq to a provider declared on premise
- **WHEN** a beheerder opens the case type's AI features
- **THEN** the provider and its residency SHALL be shown beside that feature

#### Scenario: dossiq offers no provider choice
@e2e exclude An assertion over the source tree and the case-type schema, made in PHPUnit.

- **WHEN** the case type declaration is inspected
- **THEN** it SHALL carry no provider, model or residency property

#### Scenario: Without hermiq, the features read unavailable rather than local
@e2e exclude Requires an instance with hermiq absent, which the e2e instance does not provide; asserted in PHPUnit.

- **GIVEN** an instance where hermiq is not installed
- **WHEN** the case type's AI features are read
- **THEN** each SHALL report that it is unavailable, and none SHALL report a
  provider

### Requirement: A request that reads a document carries the reference, and a refusal is shown as one (REQ-AIC-03)

Where a declared feature reads a document, dossiq SHALL pass the document
reference with the request. A request for such a feature without a reference SHALL
be refused by dossiq before it is sent.

A refusal from hermiq SHALL be rendered naming the feature and the reason, and
SHALL NOT be rendered as a generic failure.

#### Scenario: The reference travels with the request
@e2e exclude The assertion is on the outbound request shape, made in PHPUnit against the gateway.

- **GIVEN** a feature declared on a case type that reads a document
- **WHEN** it is run on a document
- **THEN** the request to hermiq SHALL carry that document's reference

#### Scenario: An unredacted document is refused in words a handler can act on
@e2e tests/e2e/ai-features-on-the-case.spec.ts

- **GIVEN** hermiq refusing on redaction
- **WHEN** a handler runs the feature
- **THEN** the case SHALL show that the feature will not read a document that has
  not been redacted, naming the feature

#### Scenario: A document-reading feature without a reference never reaches hermiq
@e2e exclude Asserted in PHPUnit: the refusal happens before any call.

- **GIVEN** a feature that reads a document
- **WHEN** it is requested with no document reference
- **THEN** dossiq SHALL refuse it, and no call SHALL be made

### Requirement: Identical reports collapse on the case, and dossiq decides what a group means (REQ-AIC-04)

For an incoming report on a case type that declares grouping, dossiq SHALL ask
hermiq which group it belongs to and SHALL render the group's count with its
near-duplicates listed beside it. dossiq SHALL NOT score similarity itself.

What a group means SHALL remain dossiq's: hermiq answers, and creates nothing.

#### Scenario: Two hundred reports read as one item with a count
@e2e tests/e2e/ai-features-on-the-case.spec.ts

- **GIVEN** two hundred reports hermiq answers as one group
- **WHEN** the handler opens the queue
- **THEN** one item SHALL be shown, carrying the count

#### Scenario: The near-duplicates are visible beside the group
@e2e tests/e2e/ai-features-on-the-case.spec.ts

- **GIVEN** a group carrying one uncertain member
- **WHEN** the group is opened
- **THEN** that report SHALL be listed beside the group, marked as one somebody
  should look at

#### Scenario: Grouping does not reduce the confirmations owed
@e2e exclude Asserted in PHPUnit: the acknowledgement count is read from the reports, never from the group.

- **GIVEN** two hundred reports collapsed into one group
- **WHEN** the confirmations of receipt owed are counted
- **THEN** two hundred SHALL be owed, one per report

#### Scenario: dossiq holds no similarity scoring of its own
@e2e exclude An assertion over the source tree, made in PHPUnit.

- **WHEN** dossiq is inspected for text-similarity scoring beside hermiq's grouping
- **THEN** none SHALL exist, and the duplicate check for one person filing twice
  SHALL be untouched

### Requirement: The conversational intake files through dossiq's own create-only path (REQ-AIC-05)

dossiq SHALL declare a create-only intake tool, annotated as hermiq's intake
grant requires, whose handler calls the same case-creation path the create form
calls. dossiq SHALL NOT add a second creation path for the intake, and SHALL NOT
relax validation for it.

dossiq SHALL declare its request catalogue, so hermiq's classification proposes
from the municipality's own list.

#### Scenario: The intake tool creates through the existing path
@e2e exclude Asserted in PHPUnit: the tool handler delegates to the same creation service the create form uses.

- **WHEN** the declared intake tool is invoked
- **THEN** the case SHALL be created through dossiq's existing creation path, with
  its own validation

#### Scenario: A conversation missing a required field is refused, not half-filed
@e2e exclude Asserted in PHPUnit; the refusal is what hermiq turns into a handover.

- **GIVEN** an intake call missing a field the case type requires
- **WHEN** it is invoked
- **THEN** it SHALL be refused, and no case SHALL be created

#### Scenario: Only the create tool is annotated for intake
@e2e exclude An assertion over the declared tool catalogue, made in PHPUnit.

- **WHEN** the tool catalogue is read
- **THEN** exactly the create-only tool SHALL carry the intake annotation, and no
  tool that reads or changes an existing case SHALL carry it

### Requirement: dossiq ships an initial prompt library and stops owning it (REQ-AIC-06)

dossiq SHALL ship the prompts it wants offered on a case as an initial library
into hermiq, and SHALL NOT hold prompt text in code for anything the assistant
offers on a case. Once an administrator has edited, reordered, scoped or disabled
a prompt, dossiq SHALL NOT restore it.

#### Scenario: The prompts offered on a case come from hermiq
@e2e tests/e2e/ai-features-on-the-case.spec.ts

- **WHEN** a handler opens the assistant on a case
- **THEN** the prompts offered SHALL be the ones hermiq holds for that record type,
  in the administrator's order

#### Scenario: An edited prompt is not overwritten by dossiq
@e2e exclude Requires re-running the seed after an administrator's edit; asserted in PHPUnit.

- **GIVEN** a shipped prompt an administrator has edited
- **WHEN** dossiq's library is seeded again
- **THEN** the edited text SHALL stand
