# avg-processing-surface

## ADDED Requirements

### Requirement: A data subject request is a case, and the platform does the erasing (REQ-AVG-DSR-01)

A request from a data subject SHALL be handled as a case of the
`data-subject-request` type, covering inzage, correctie and verwijdering.
The handling SHALL drive OpenRegister's data subject rights capability:
a verwijdering asks the platform for an erasure preview and shows what it
reports, and an inzage asks the platform for a subject export. Dossiq SHALL
hold no erasure, pseudonymisation or export engine of its own, and the
statutory month the law allows SHALL be the case type's own processing
deadline rather than a second clock.

#### Scenario: The preview lands on the case with its grounds
@e2e tests/e2e/data-subject-requests-drive-the-platform.spec.ts

- **GIVEN** a verwijdering case naming a data subject
- **WHEN** the handler asks for the preview
- **THEN** the case SHALL carry the platform's counts for objects, files, timeline entries and party records
- **AND** every protected item SHALL be named on the case with the ground, the basis and the action a handler can take

#### Scenario: Nothing is erased by dossiq itself
@e2e exclude The absence of an engine is a property of the tree, not of a screen; PlatformDataSubjectRightsTest::testAnOlderOpenRegisterSaysSo and ::testThePlatformsRuleAndStatusAreCarriedAcross assert every act resolves to an OpenRegister service and that its refusal keeps the platform's own rule.

- **WHEN** dossiq's services are inspected
- **THEN** no erasure, pseudonymisation or export engine SHALL exist in dossiq
- **AND** every act SHALL be an OpenRegister call whose refusal is passed back with the platform's own rule name

#### Scenario: The month comes from the case type
@e2e exclude The term binding is read by TermDeclarationReader and asserted in DataSubjectRequestTemplateTest::testTheStatutoryMonthIsTheCaseTypesOwnDeadline; no screen shows the derivation.

- **GIVEN** a new data subject request
- **WHEN** its term is bound
- **THEN** the term SHALL come from the case type's processing deadline of one month
- **AND** dossiq SHALL declare no deadline of its own for this type

### Requirement: An erasure runs only after a second person approves it (REQ-AVG-DSR-02)

An erasure SHALL run only from a preview the case has approved, and the
approval SHALL be a transition the person who prepared the preview may not
take. The run's outcome SHALL be written to the case and to its timeline,
naming what was destroyed, what was pseudonymised, what was withheld and
what failed.

#### Scenario: The preparer cannot approve their own erasure
@e2e tests/e2e/data-subject-requests-drive-the-platform.spec.ts

- **GIVEN** a handler who prepared the erasure preview
- **WHEN** they try to take the approving transition
- **THEN** the move SHALL be refused
- **AND** the refusal SHALL name the earlier act and say who to ask instead

#### Scenario: A run without the approving act is refused
@e2e exclude The refusal is server-side and reached before any screen offers the act; DataSubjectRequestCaseTest::testARunWithoutTheApprovingActIsRefused asserts the rule, the sentence and that the platform is never asked.

- **GIVEN** a case whose preview has not been approved on the case
- **WHEN** the run is started
- **THEN** it SHALL be refused with the rule `erasure-not-approved`
- **AND** nothing SHALL be written to the case

#### Scenario: The outcome reaches the timeline
@e2e tests/e2e/data-subject-requests-drive-the-platform.spec.ts

- **GIVEN** an approved preview that has been run
- **WHEN** the case timeline is read
- **THEN** it SHALL hold one entry of the declared AVG kind
- **AND** that entry SHALL name the counts the platform returned

### Requirement: An incomplete erasure keeps the case open, and says what is left (REQ-AVG-DSR-03)

A verwijdering case SHALL NOT be closed while the platform reports the
erasure incomplete. The reason offered SHALL name what was withheld rather
than reporting that a condition failed. An inzage case SHALL carry the
platform's export and offer the download only while the platform says the
file is ready and unexpired.

#### Scenario: The close is withheld and says what is left
@e2e tests/e2e/data-subject-requests-drive-the-platform.spec.ts

- **GIVEN** a run that reported two withheld objects and was not complete
- **WHEN** the handler looks for the closing move
- **THEN** the move SHALL be withheld
- **AND** the reason SHALL name the withheld objects

#### Scenario: A complete erasure lets the case close
@e2e exclude The settled path is the absence of a reason; ErasureCompletePreconditionTest::testACompleteRunLetsTheCaseClose and ::testTheReasonNamesWhatWasWithheld pin both sides of the same guard.

- **GIVEN** a run the platform reported complete
- **WHEN** the same move is offered
- **THEN** it SHALL be available

#### Scenario: An expired export is not offered
@e2e exclude Expiry is the platform's answer, asserted in DataSubjectRequestCaseTest::testAnExpiredExportIsNotOffered and ::testAnUnfinishedExportIsNotCalledExpired; waiting seven days in a browser is not a test.

- **GIVEN** an inzage case whose export has passed its seven day life
- **WHEN** the case is read
- **THEN** the download SHALL NOT be offered
- **AND** the case SHALL say the export expired and can be asked for again
