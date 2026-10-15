# leaf-integrations Delta: a-request-form-opens-the-case-at-once

**Status**: draft
**Scope**: the Nextcloud Forms intake follows the one start date rule and goes through the form submit (decisions 181 and 218).

## MODIFIED Requirements

### Requirement: REQ-LEAF-103 — Forms citizen intake creates cases through the intake conventions

Dossiq MUST add an optional `intakeFormRef` string property to the `caseType` schema, and a Forms submission listener + `FormsIntakeService` (`lib/Service/FormsIntakeService.php`) that, for submissions of a bound form, creates a `case` of that `caseType` with the caseType's initial status and `intakeChannel: "forms"`. The service SHALL NOT write `startDate`: the receipt listener sets the receipt and start dates in the same save by the rule every case follows (the receipt day, or the later day the term starts, decision 218), and the materialised `deadline` counts from that start date. The case SHALL be written through OpenRegister's form submit with the case type's `intakeFormMapping`, and through the object service where OpenRegister has no form submit. The case MUST be created through `FormsIntakeService`, never by a raw `ObjectService` insert from the frontend. Submissions of forms not bound by any `caseType.intakeFormRef` MUST create nothing.

The service MUST re-check the form hash on every row the store hands back, rather than trusting the filter. A store that does not recognise a filter key answers the whole register, confidently and with no error, and the first row of that answer would open a case of a type nobody bound.

Neither the listener nor the service MAY import or type hint a class from `OCA\Forms`. The app is optional, and a type hint on a class the instance does not have is a fatal when the container builds the listener rather than a feature that is quietly missing.

#### Scenario: Bound form submission opens a case
@e2e exclude no Forms app is installed on the e2e rig, so a submission cannot be made; covered by `tests/Unit/Service/FormsIntakeServiceTest.php::testABoundFormOpensACaseOfThatType` and `::testTheClockStartsAtTheSubmission`

- **WHEN** a citizen submits the Forms form whose hash matches a caseType's `intakeFormRef`
- **THEN** a case of that caseType SHALL exist with the caseType's initial status and `intakeChannel` = "forms"
- **AND** the service SHALL have written no `startDate` of its own

#### Scenario: Unbound form submission is inert
@e2e exclude same rig limit; covered by `tests/Unit/Service/FormsIntakeServiceTest.php::testAnUnboundFormOpensNothing` and `::testAStoreThatIgnoresTheFilterOpensNothing`

- **WHEN** a form whose hash matches no `intakeFormRef` receives a submission
- **THEN** no case SHALL be created and no error SHALL be logged above info level

#### Scenario: Forms app absent
@e2e tests/e2e/leaf-integrations.spec.ts

- **WHEN** the `forms` app is not enabled
- **THEN** the listener SHALL never be registered and caseType editing SHALL still accept and store `intakeFormRef`
