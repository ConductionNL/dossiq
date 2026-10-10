# Tasks: a-request-form-opens-the-case-at-once

## 1. Term fields on create

- [ ] 1.1 Move `IntakeTermStartListener` to `ObjectCreatingEvent`; one save
  - Spec ref: specs/case-intake-destination/spec.md, "The case create MUST carry its term fields"
  - Files: lib/Listener/IntakeTermStartListener.php, lib/AppInfo/Application.php
  - Test: unit test asserting the fields on the creating object; a control asserting no second save
- [ ] 1.2 `startDate` = date of `termStartsAt` for submit-opened cases (Q11)
- [ ] 1.3 Schema markers `x-openregister.confirmation` and `x-openregister.serverSet` on the case properties
  - Files: lib/Settings/dossiq_register.json

## 2. Intake requirement D-5 (intake-says-when-the-term-starts task 2.1)

- [x] 2.1 Amend D-5 in `intake-says-when-the-term-starts/design.md` (done in this change's pull request)
- [ ] 2.2 Close task 2.1 of `intake-says-when-the-term-starts` once portaliq's `submit-creates-the-case-directly` renders the four fields from the response; run its e2e scenario "the citizen is told on screen"

## 3. Bezwaar and klacht

- [ ] 3.1 Point `createBezwaar` and `createKlacht` at `case` with fixed `caseType`, cross-references and attachments as documents
  - Files: lib/Portal/CitizenManifest.php
- [ ] 3.2 `occ dossiq:intake:drain-portaalverzoek`, report delivered and refused; old id to `externalReference`
- [ ] 3.3 Remove `portaalVerzoek` from register.d/50-zaakportaal.json, SchemaSlugMap and ConfigKeys when the drain reports zero

## 4. Other intake paths

- [ ] 4.1 `WooRequestIntake::writeCase` returns `receivedAt` and `termStartsAt` with the identifier and deadline; the confirmation template gains the term start
- [ ] 4.2 `FormsIntakeService`: binding maps answers to typed properties, checked at bind time through OpenRegister's validator (Q6)
- [ ] 4.3 Case-type publish re-checks bound forms (`caseType.intakeFormRef` and portaliq bindings), including `requiredBeforeCreation`

## 5. Verification

- [ ] 5.1 `composer check:strict`, `npm run lint`, `openspec validate a-request-form-opens-the-case-at-once --strict`
