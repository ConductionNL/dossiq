# burger-notifications Delta: a-request-form-opens-the-case-at-once

**Status**: draft
**Scope**: a case that comes in through a Nextcloud Forms form owes an acknowledgement of receipt by default (decision 236).

## MODIFIED Requirements

### Requirement: The acknowledgement of receipt has a trigger (REQ-TERM-020)

A case created from an electronic submission SHALL trigger an
ontvangstbevestiging without a person acting, per Awb 4:3a. The trigger
SHALL be a declaration on the case type and SHALL NOT be a step in a
workflow definition. A case type SHALL declare which intake channels owe
an acknowledgement, defaulting to every electronic channel: `email`,
`website`, `forms` and `zgw-api`. A case created by hand SHALL NOT owe one
unless the case type says so.

A case that comes in through a Nextcloud Forms form (`intakeChannel`
`forms`) SHALL owe an acknowledgement by default (decision 236). A case type
SHALL be able to switch that off by naming its own `intakeChannels` list
without `forms`; the channels it does name SHALL keep owing one.

The acknowledgement SHALL be sent once per case, at the moment the case is
received. A draft SHALL NOT be acknowledged: not when it is first saved and
not by a job that reaches it while it is still a draft. Sending the draft
SHALL queue the acknowledgement once (decisions 180 and 227). An address
that fails validation SHALL NOT be handed to a transport; a case with no
valid address SHALL refuse as a case without an address and SHALL record
the notice as not sent.

#### Scenario: an aanvraag arrives through the portal
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type with the default declaration
- **WHEN** a citizen submits an aanvraag through the portal
- **THEN** an ontvangstbevestiging SHALL be sent to them
- **AND** no handler action SHALL be needed

#### Scenario: an aanvraag arrives by mail
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case created from an accepted inbound message
- **WHEN** the case is created
- **THEN** an ontvangstbevestiging SHALL be sent to the sender

#### Scenario: a case typed at the balie owes nothing
@e2e tests/e2e/ontvangstbevestiging.spec.ts

- **GIVEN** a case type whose declaration names electronic channels only
- **WHEN** a handler creates a case by hand
- **THEN** no ontvangstbevestiging SHALL be sent

#### Scenario: a case type cannot silently lose the duty
@e2e exclude a warning in the publish response, with no screen of its own; covered by `tests/Unit/Service/CaseTypeAcknowledgementTest.php::testSwitchingTheDutyOffWarnsAndNamesTheArticle`

- **GIVEN** a case type whose acknowledgement declaration is removed
- **WHEN** it is published
- **THEN** publication SHALL warn that the statutory acknowledgement is off
- **AND** the warning SHALL name Awb 4:3a

#### Scenario: a request arrives through a Nextcloud Forms form
@e2e exclude no Forms app is installed on the e2e rig; covered by `tests/Unit/Service/AcknowledgementDutyTest.php::testAnElectronicIntakeIsConfirmedOnceAndRecorded` (data set "a Nextcloud Forms form") and `tests/Unit/Service/CaseTypeAcknowledgementTest.php::testAFormsCaseOwesOneUnlessTheCaseTypeSwitchesItOff`

- **GIVEN** a case type that declares nothing about its acknowledgement, bound to a Nextcloud Forms form
- **WHEN** somebody submits the form with their e-mail address
- **THEN** one ontvangstbevestiging SHALL be sent to that address
- **AND** a second run for the same case SHALL send nothing

#### Scenario: a case type switches the forms acknowledgement off
@e2e exclude no Forms app is installed on the e2e rig; covered by `tests/Unit/Service/AcknowledgementDutyTest.php::testACaseTypeSwitchesTheFormsAcknowledgementOff`

- **GIVEN** a case type whose `acknowledgement.intakeChannels` names `email`, `website` and `zgw-api`
- **WHEN** a case of that type comes in through a Nextcloud Forms form
- **THEN** no ontvangstbevestiging SHALL be sent
- **AND** a case of that type from the portal SHALL still get one

#### Scenario: a draft is acknowledged when it is sent, not when it is saved
@e2e exclude a draft is saved and sent through the portal, which the dossiq e2e rig does not run; covered by `tests/Unit/Service/AcknowledgementDutyTest.php::testADraftIsNotConfirmedUntilItIsSent` and `tests/Unit/AppInfo/DraftIsNotReceivedRegistrationTest.php::testASentDraftDoesItsCreationWorkOnceUnderBothEventModels`

- **GIVEN** a draft case
- **WHEN** it is saved, or a queued acknowledgement reaches it
- **THEN** nothing SHALL be sent and nothing SHALL be recorded on the case
- **WHEN** the draft is sent
- **THEN** one ontvangstbevestiging SHALL be queued

#### Scenario: an address that fails validation gets no mail
@e2e exclude no Forms app is installed on the e2e rig; covered by `tests/Unit/Service/AcknowledgementDutyTest.php::testAnAddressThatFailsValidationIsNeverMailed`

- **GIVEN** a forms case whose e-mail answer reads `jan at example`
- **WHEN** the acknowledgement runs
- **THEN** no transport SHALL be called
- **AND** the case SHALL record the notice as not sent, with the duty not met
