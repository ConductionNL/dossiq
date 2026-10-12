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

## 2. Intake requirement D-5 (intake-says-when-the-term-starts task 2.1)

- [x] 2.1 Amend D-5 in `intake-says-when-the-term-starts/design.md` (done in this change's pull request)
- [ ] 2.2 Close task 2.1 of `intake-says-when-the-term-starts` once portaliq's `submit-creates-the-case-directly` renders the four fields from the response; run its e2e scenario "the citizen is told on screen"

## 3. Bezwaar and klacht

- [x] 3.1 Point `createBezwaar` and `createKlacht` at `case`, with `caseType` stamped from the setting `portal_filing_case_types` (a JSON map of action id to case type; default the shipped `bezwaar` / `klacht-behandeling`, decision 182), `contestedCase` as cross-reference and `attachments` on the case
  - Files: lib/Portal/CitizenManifest.php, lib/Portal/PortalContributionProvider.php, lib/Service/Intake/IntakeCaseTypes.php
  - Open: attachments become `caseDocument` rows (portaliq's submit), and the klacht `category` has no case property yet
- [x] 3.2 `occ dossiq:intake:drain-portaalverzoek`, report delivered and refused; old id to `externalReference`
- [ ] 3.3 Remove `portaalVerzoek` from register.d/50-zaakportaal.json, SchemaSlugMap and ConfigKeys when the drain reports zero

## 4. Other intake paths

- [x] 4.1 `WooRequestIntake::writeCase` returns `receivedAt` and `termStartsAt` with the identifier and deadline; the confirmation template gains the term start
- [ ] 4.2 `FormsIntakeService`: binding maps answers to typed properties, checked at bind time through OpenRegister's validator (decision 181)
  - [x] 4.2a The case schema lists `forms` as an intake channel (case 1.45.0, and the case type's acknowledgement channel list, caseType 1.20.0). The service wrote that value from the start, as `leaf-integrations` asks, and the schema refused it
    - Test: tests/Unit/Service/FormsIntakeServiceTest.php, `testTheRealCaseSchemaAcceptsTheCaseAsWritten` (the written payload against the real case schema)
- [ ] 4.3 Case-type publish re-checks bound forms (`caseType.intakeFormRef` and portaliq bindings), including `requiredBeforeCreation`

## 5. Verification

- [ ] 5.1 `composer check:strict`, `npm run lint`, `openspec validate a-request-form-opens-the-case-at-once --strict`
