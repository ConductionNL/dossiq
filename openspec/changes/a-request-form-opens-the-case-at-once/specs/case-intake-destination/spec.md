# case-intake-destination Delta: a-request-form-opens-the-case-at-once

**Status**: draft
**Scope**: dossiq case schema, intake listeners, portal actions, case-type publishing. Implements hydra `form-submits-into-its-destination-object` for dossiq.

## ADDED Requirements

### Requirement: The case create MUST carry its term fields

`receivedAt`, `termStartsAt` and `receivedOutsideWorkingHours` SHALL be set before the case is saved, so the create result holds them. A case in OpenRegister status `draft` SHALL carry none of them; they SHALL be set on the save that moves the case out of `draft` (decision 180). When dossiq sets `startDate` and the term starts on a later day than the request came in, `startDate` SHALL be the day of `termStartsAt` (decision 181). Sending a draft SHALL set `startDate` again, from the moment it was sent.

#### Scenario: A Sunday request returns its term in the submit response

- **GIVEN** a request form into case type `omgevingsvergunning` with a processing term of 8 weeks
- **WHEN** a resident submits it on Sunday 11 October 2026 at 21:40
- **THEN** the submit response carries the case `identifier`, `receivedAt` 2026-10-11T21:40, `termStartsAt` 2026-10-12 and `deadline` 2026-12-07
- **AND** the case audit trail holds one create, not a create and an update

#### Scenario: A draft case starts no term
- **GIVEN** a resident who saves a request form as a draft on Friday
- **WHEN** the case is read
- **THEN** it is in status `draft` with no `receivedAt` and no `termStartsAt`
- **AND** no acknowledgement is queued

#### Scenario: Sending the draft starts the term
- **GIVEN** that draft, completed and sent on Sunday 11 October 2026 at 21:40
- **WHEN** the case leaves `draft`
- **THEN** `receivedAt` is 2026-10-11T21:40 and `termStartsAt` is 2026-10-12
- **AND** `startDate` is 2026-10-12, not the Friday the draft was begun
- **AND** the acknowledgement is queued once

### Requirement: A draft case MUST NOT be treated as received

A case in OpenRegister status `draft` SHALL NOT get a case number, SHALL NOT be placed in a group of similar reports and SHALL NOT get a case plan. Each SHALL happen once, when the draft is sent, on the event OpenRegister sends for it (decisions 180 and 201). A case created outside `draft` SHALL get all three on its create, as before.

OpenRegister announces a sent draft in one of two ways, and both SHALL give the same result. On OpenRegister without decision 227 a draft's create is a created event for a draft and sending it is an activated event. On OpenRegister with decision 227 a draft's create reaches no created listener, and sending it is a created event followed by an activated event for the same object. A listener that sits on both events SHALL do its creation work once per case under either: the case number, the grouping, the case plan, the acknowledgement of receipt and the term binding.

#### Scenario: A draft has no case number until it is sent
@e2e exclude a draft is saved and sent through the portal, which the dossiq e2e rig does not run; covered by `tests/Unit/AppInfo/DraftIsNotReceivedRegistrationTest.php::testADraftIsNumberedWhenItIsSent`

- **GIVEN** a resident who saves a request form as a draft
- **WHEN** the draft case is stored
- **THEN** no case number is assigned, no grouping job is queued and no case plan is projected
- **WHEN** the resident sends it
- **THEN** the case gets its number, is queued for grouping once and gets its plan

#### Scenario: A sent draft is announced twice and handled once
@e2e exclude the two events come from OpenRegister inside one save and need an OpenRegister with decision 227, which is not on development; covered by `tests/Unit/AppInfo/DraftIsNotReceivedRegistrationTest.php::testASentDraftDoesItsCreationWorkOnceUnderBothEventModels`

- **GIVEN** an OpenRegister that announces a sent draft as created and then as activated
- **WHEN** a resident sends a draft case
- **THEN** the case gets one case number, one grouping job, one case plan, one queued acknowledgement and one term
- **GIVEN** an OpenRegister that announces a sent draft as activated only
- **WHEN** a resident sends a draft case
- **THEN** the result is the same

### Requirement: The confirmation fields MUST be marked on the case schema

`identifier`, `receivedAt`, `termStartsAt` and `deadline` SHALL carry `x-openregister.confirmation: true`. `identifier`, `receivedAt`, `termStartsAt` and `receivedOutsideWorkingHours` SHALL carry `x-openregister.serverSet: true`.

#### Scenario: A form without the server fields passes the destination check

- **GIVEN** a request form mapping `title`, `requester`, `communicationChannel` and `confidentiality`, with fixed `caseType`
- **WHEN** it is validated against the case schema
- **THEN** the validator returns zero findings

### Requirement: A case type's intake form MUST be checked against the case schema when it is bound

A case type MAY carry `intakeFormMapping` next to `intakeFormRef`: `fields`, one entry per answer with the question (`field`), the case property it fills (`property`) and the kind of answer (`type`), and `fixed`, the values every case of this form gets. Saving a case type that has `intakeFormRef` set SHALL validate that mapping against the case schema with OpenRegister's form destination validator, before the case type is stored. The mapping judged SHALL include what the binding itself writes: `caseType`, `intakeChannel` `forms`, the case type's first status, and a title when no answer fills it. A save with findings SHALL be refused and SHALL return the findings (decision 181). A submission of the bound form SHALL be written through OpenRegister's form submit with that same mapping. Where OpenRegister has no validator the case type SHALL be saved and the missing check SHALL be logged.

#### Scenario: An answer into a property the case does not have is refused at the save
@e2e exclude no Forms app is installed on the e2e rig; covered by `tests/Unit/AppInfo/IntakeFormBindingRegistrationTest.php::testANewCaseTypeWithAWrongBindingIsRefused`

- **GIVEN** a case type with `intakeFormRef` set and a mapping whose answer "Onderwerp" fills `onderwerp`
- **WHEN** the case type is saved
- **THEN** the save is refused with finding `property-unknown` on `onderwerp`
- **AND** the case type is not stored

#### Scenario: A binding without a mapping keeps working
@e2e exclude no Forms app is installed on the e2e rig; covered by `tests/Unit/Service/Intake/IntakeFormBindingTest.php::testABindingWithoutAnswersKeepsWhatItDid`

- **GIVEN** a case type with `intakeFormRef` set and no `intakeFormMapping`
- **WHEN** the bound form is submitted
- **THEN** the case is named after the first answer and its description lists every answer
- **AND** the case type saves without findings

### Requirement: Publishing a case type MUST re-check the forms bound to it

Publishing a case type SHALL validate every form bound to it against the case schema plus that type's `intakeRequirements.requiredBeforeCreation`. A finding SHALL block the publish or unpublish the form, per the `formDestinationBreak` setting. The case type's own intake form (`intakeFormRef`) is part of the case type, so a finding on it SHALL refuse the save: every `requiredBeforeCreation` property SHALL be filled by an answer or a fixed value of `intakeFormMapping` on a case type that is not a draft. A draft case type SHALL NOT be held to that list until it is published.

#### Scenario: A new intake requirement catches a form that lacks it
@e2e exclude the forms are another app's (portaliq) and the e2e rig runs dossiq alone; covered by `tests/Unit/AppInfo/BoundFormsRegistrationTest.php::testByDefaultTheBrokenFormIsUnpublishedAfterTheSaveAndItsAuthorIsTold` and `::testAFormThatLacksANewIntakeRequirementRefusesThePublishWhereItsAppSaysSo`

- **GIVEN** a published portal form bound to case type `klacht` that does not ask for `communicationChannel`
- **WHEN** `communicationChannel` is added to that type's `requiredBeforeCreation` and the type is published
- **THEN** where portaliq's `formDestinationBreak` is `refuse`, the save is refused with rule `bound-form-invalid`, naming the form with finding `required-unmapped`
- **AND** otherwise the case type is saved, the form is unpublished and its author is told

#### Scenario: A case schema change re-checks the case types' own Forms bindings
@e2e exclude no Forms app is installed on the e2e rig; covered by `tests/Unit/AppInfo/BoundFormsRegistrationTest.php::testDossiqAnnouncesItsOwnFormsBindingsForTheCaseSchema`

- **GIVEN** a published case type with a Nextcloud Forms form bound in `intakeFormRef`
- **WHEN** OpenRegister asks which forms write into the case schema before that schema changes
- **THEN** dossiq names the binding with its mapping and the case type's owner
- **AND** when the change breaks it, the binding is taken off the case type and its mapping is kept

#### Scenario: Publishing a case type whose own intake form leaves out an intake requirement is refused
@e2e exclude no Forms app is installed on the e2e rig; covered by `tests/Unit/AppInfo/IntakeFormBindingRegistrationTest.php::testPublishingReChecksTheFormAgainstTheIntakeRequirements`

- **GIVEN** a draft case type with `intakeFormRef` set, `requiredBeforeCreation` naming `communicationChannel`, and a mapping that does not fill it
- **WHEN** the case type is saved as a draft
- **THEN** the save succeeds
- **WHEN** it is published
- **THEN** the save is refused with finding `required-unmapped` on `communicationChannel`

### Requirement: A portal filing MUST NOT be held in an intermediate object

The portal's bezwaar and klacht actions SHALL submit into `case`. The case type each action opens SHALL come from the app setting `portal_filing_case_types`, which maps an action id to a case type; an action without a known case type SHALL NOT be offered. The `portaalVerzoek` schema SHALL be drained by `occ dossiq:intake:drain-portaalverzoek`, which turns each object into a case or reports it with findings, and SHALL be removed when the report shows zero pending.

#### Scenario: An old portaalVerzoek becomes a case
- **GIVEN** a stored `portaalVerzoek` of kind `bezwaarschrift` against case Z/2026/09128
- **WHEN** the drain runs
- **THEN** a bezwaar case exists with `contestedCase` Z/2026/09128 and the old id as `externalReference`
- **AND** the report counts it as delivered
