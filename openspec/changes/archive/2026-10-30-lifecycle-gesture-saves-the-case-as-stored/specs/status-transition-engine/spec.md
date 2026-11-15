# status-transition-engine delta: lifecycle-gesture-saves-the-case-as-stored

## ADDED Requirements

### Requirement: A lifecycle gesture saves the case as the term left it (REQ-STE-15)

Suspending, resuming, extending and reopening a case SHALL save the case as it is
stored after the statutory term moved, plus only the fields the gesture itself
sets. A gesture SHALL NOT save a copy of the case read before the term moved: the
deadline mirror writes the term's new end onto the case during the term gesture,
and `deadline` is readOnly. An unexpected failure SHALL answer with the code
`change_failed`, which the page translates.

#### Scenario: Pausing a case with a statutory term succeeds
@e2e exclude the readOnly refusal comes from OpenRegister's store, so it is reproduced by a store fake that refuses the same way; proven by tests/Unit/Service/CaseLifecycleServiceTest.php::testPauseOnATermSavesTheCaseTheMirrorWrote

- **GIVEN** a case with a statutory term instance
- **WHEN** a handler pauses it and the mirror moves the case's deadline
- **THEN** the gesture SHALL succeed and the case SHALL keep the term's new end

#### Scenario: Extending a case with a statutory term succeeds
@e2e exclude the readOnly refusal comes from OpenRegister's store, so it is reproduced by a store fake that refuses the same way; proven by tests/Unit/Service/CaseLifecycleServiceTest.php::testExtendOnATermSavesTheCaseTheMirrorWrote

- **GIVEN** a case with a statutory term instance
- **WHEN** a handler extends it
- **THEN** the case SHALL carry the new planned end date and extension count on top of the deadline the mirror wrote

#### Scenario: An unexpected failure answers a code the page translates
@e2e exclude an unexpected failure cannot be provoked from a browser; proven by tests/Unit/Controller/CaseLifecycleControllerTest.php::testAnUnexpectedFailureWithholdsItsDetail

- **GIVEN** a gesture that fails for a reason that is not a refusal
- **WHEN** the controller answers
- **THEN** the answer SHALL be HTTP 500 with `code` `change_failed` and no detail of the failure
