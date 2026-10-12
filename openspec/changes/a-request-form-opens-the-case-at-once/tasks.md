# Tasks: a-request-form-opens-the-case-at-once

## 1. Term fields on create

- [x] 1.1 `IntakeTermStartListener` sets the fields before the store, on `ObjectCreatingEvent` and on the move out of `draft`; one save; skip draft cases. Built on the listener `receipt-and-start-dates-are-set-at-creation` landed, not beside it
  - Spec ref: specs/case-intake-destination/spec.md, "The case create MUST carry its term fields"
  - Files: lib/Listener/IntakeTermStartListener.php, lib/AppInfo/Registrar/IntakeListenerRegistrar.php
  - Test: tests/Unit/Listener/IntakeTermStartListenerTest.php (the payload of the real `createBezwaar` action, validated against the real case schema; no second save); wiring in tests/Unit/AppInfo/ReceiptDatesRegistrationTest.php
- [x] 1.2 `startDate` = the day of `termStartsAt` when the term starts later than the receipt day (decision 181), in `CaseReceiptDates`; REQ-TERM-042 amended
  - Open point for openregister: the schema's `startDate` and `deadline` calculations also run on a draft, so a draft shows the day it was begun and a deadline. Sending it corrects both. Hiding them on a draft needs the calculation to know the draft status
- [x] 1.3 Schema markers `x-openregister.confirmation` and `x-openregister.serverSet` on the case properties
  - Files: lib/Settings/dossiq_register.json

- [x] 1.4 Receipt listeners (`AcknowledgementOnCreateListener`, `DeadlineCaseCreatedListener`, `IntakeRequirementsListener`) skip `draft` cases and run on the move out of `draft` (decision 180), through the generic `ReceiptMoment`
  - Routing: dossiq has no create-time routing listener. The case flow `Case behandeling` (trigger `object.created`) sets the intake status and role; skipping drafts there needs an OpenRegister flow trigger that knows the draft status (open point for openregister)
  - Test: a draft create queues no acknowledgement; leaving draft queues exactly one

- [x] 1.5 `CaseNumberListener`, `ReportGroupingOnCreateListener` and `CasePlanProjectionListener` skip a draft and run when it is sent (decisions 180 and 201), through `ReceiptMoment::receivedObject`; each is registered on OpenRegister's `ObjectActivatedEvent` next to its create registration
  - Test: tests/Unit/AppInfo/DraftIsNotReceivedRegistrationTest.php (from both registrars to the effect, for a draft create, a received create and a sent draft)
  - Open for openregister: the case flow starts on the flow trigger `object.created`, also for a draft, and the schema's `startDate` and `deadline` calculations run on a draft (Q-dossiq-d179-3)

## 2. Intake requirement D-5 (intake-says-when-the-term-starts task 2.1)

- [x] 2.1 Amend D-5 in `intake-says-when-the-term-starts/design.md` (done in this change's pull request)
- [ ] 2.2 Close task 2.1 of `intake-says-when-the-term-starts` once portaliq's `submit-creates-the-case-directly` renders the four fields from the response; run its e2e scenario "the citizen is told on screen"
  - [x] 2.2a Checked on portaliq development (7b7ae1fd, its task 2.4): the confirmation page shows the case number and, from the submit response, the received moment, the term start and the deadline (`src/site/components/forms/confirmation.js` `momentLines`; the mail in `lib/Service/Intake/FormConfirmationMailer.php`). Task 2.1 of `intake-says-when-the-term-starts` is updated with this
  - [ ] 2.2b The sentence about the first working day is not on that screen. The scenario asks for it, the response does not carry `receivedOutsideWorkingHours` (it is not a confirmation field), and `momentLines` has no line for it. Either portaliq derives it (the term starts on a later day than the request came in) or the case schema marks the field as a confirmation field; question Q-dossiq-d179-2
  - [ ] 2.2c (live pass, decision 139) Run `tests/e2e/intake-says-when-the-term-starts.spec.ts`, scenario "the citizen is told on screen"

## 3. Bezwaar and klacht

- [x] 3.1 Point `createBezwaar` and `createKlacht` at `case`, with `caseType` stamped from the setting `portal_filing_case_types` (a JSON map of action id to case type; default the shipped `bezwaar` / `klacht-behandeling`, decision 182), `contestedCase` as cross-reference and `attachments` on the case
  - Files: lib/Portal/CitizenManifest.php, lib/Portal/PortalContributionProvider.php, lib/Service/Intake/IntakeCaseTypes.php
  - Open: attachments become `caseDocument` rows (portaliq's submit), and the klacht `category` has no case property yet
- [x] 3.2 `occ dossiq:intake:drain-portaalverzoek`, report delivered and refused; old id to `externalReference`
- [ ] 3.3 Remove `portaalVerzoek` from register.d/50-zaakportaal.json, SchemaSlugMap and ConfigKeys when the drain reports zero

## 4. Other intake paths

- [x] 4.1 `WooRequestIntake::writeCase` returns `receivedAt` and `termStartsAt` with the identifier and deadline; the confirmation template gains the term start
- [x] 4.2 `FormsIntakeService`: binding maps answers to typed properties, checked at bind time through OpenRegister's validator (decision 181)
  - `caseType.intakeFormMapping` (`fields`, `fixed`; caseType 1.21.0). `IntakeFormBinding` turns the binding into the mapping, `IntakeFormBindingCheck` hands it to OpenRegister's `FormDestinationValidator` with the live case schema, `IntakeFormBindingListener` refuses the case type save on findings (pre-persist, registered in `IntakeListenerRegistrar`). The submit goes through `FormSubmitService::submit` with the same mapping; without it the object service saves the same object
  - The service no longer writes `startDate`: the receipt listener sets it by the one rule (decision 218). REQ-LEAF-103 amended in this change
  - Files: lib/Service/Intake/IntakeFormBinding.php, lib/Service/Intake/IntakeFormBindingCheck.php, lib/Listener/IntakeFormBindingListener.php, lib/Service/FormsIntakeService.php
  - Test: tests/Unit/AppInfo/IntakeFormBindingRegistrationTest.php (from the registrar to the refusal), tests/Unit/Service/Intake/IntakeFormBindingTest.php, tests/Unit/Service/Intake/IntakeFormBindingCheckTest.php, tests/Unit/Service/FormsIntakeServiceTest.php
  - Open: a multiple-choice question arrives as several answers under one question and `FormSubmittedListener` keeps the last one. dossiq does not answer OpenRegister's `FormDestinationDependentsEvent` for these bindings yet, so a case schema change does not re-check them
  - [x] 4.2a The case schema lists `forms` as an intake channel (case 1.45.0, and the case type's acknowledgement channel list, caseType 1.20.0). The service wrote that value from the start, as `leaf-integrations` asks, and the schema refused it
    - Test: tests/Unit/Service/FormsIntakeServiceTest.php, `testTheRealCaseSchemaAcceptsTheCaseAsWritten` (the written payload against the real case schema)
- [ ] 4.3 Case-type publish re-checks bound forms (`caseType.intakeFormRef` and portaliq bindings), including `requiredBeforeCreation`
  - [x] 4.3a The case type's own intake form: `IntakeFormBindingCheck::unmetRequirements` adds a `required-unmapped` finding for every `requiredBeforeCreation` property the mapping does not fill, on a case type that is not a draft; the save is refused by the same listener
    - Test: tests/Unit/AppInfo/IntakeFormBindingRegistrationTest.php `testPublishingReChecksTheFormAgainstTheIntakeRequirements`, tests/Unit/Service/Intake/IntakeFormBindingCheckTest.php
  - [ ] 4.3b portaliq bindings. portaliq development (7b7ae1fd) checks a binding when the binding is saved (`BindingDestinationGuardListener`) and answers nothing when a destination changes: no class in its `lib/` listens for OpenRegister's `FormDestinationDependentsEvent`. Owed in portaliq: a listener on that event that lists its published bindings into the schema as `FormDependent` (mapping, author, an unpublish closure). Owed here after it: on a case type publish, dispatch the event for the case schema, keep the forms whose fixed `caseType` is this type, add `unmetRequirements`, and hand the result to OpenRegister's `FormDependentsCheck::apply` (`formDestinationBreak`)

## 5. Verification

- [ ] 5.1 `composer check:strict`, `npm run lint`, `openspec validate a-request-form-opens-the-case-at-once --strict`
