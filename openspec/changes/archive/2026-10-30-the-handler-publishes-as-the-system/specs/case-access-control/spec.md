# case-access-control delta: the-handler-publishes-as-the-system

## ADDED Requirements

### Requirement: An act that writes another app's register runs as the system, for the case's handler at its step (REQ-ACC-13)

An act on a case that writes to a register the handler holds no rights on (publishing a decision into OpenCatalogi, withdrawing it) SHALL run as the system, and only after dossiq has checked that the caller is the case's handler and that the case is at the act's step. A caller who is not the handler SHALL be refused with 403, and a case not at the step with 422, both with a sentence in the reader's language. Neither refusal SHALL reach the write, and neither SHALL answer 500.

#### Scenario: The handler publishes the decision
@e2e exclude {asserted in tests/Unit/Controller/WOOAssessmentControllerAuthorizationTest.php with an object service that records the system run; the OpenCatalogi write is not available to e2e}

- **GIVEN** a Woo request whose decision is ready to publish, claimed by Thomas
- **WHEN** Thomas presses Publiceren (Woo)
- **THEN** the publication SHALL be written as the system
- **AND** the case SHALL read published

#### Scenario: Somebody else is refused
- **GIVEN** the same case, and a team member who has not claimed it
- **WHEN** they post the publish
- **THEN** the answer SHALL be 403 with "Pak deze zaak eerst op: alleen de behandelaar kan dit doen."
- **AND** nothing SHALL be published
