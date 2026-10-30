## ADDED Requirements

### Requirement: A handler registers a notice of default on the case (REQ-ING-CASE-01)

On a case whose case type carries a penalty on a late decision, the case page SHALL offer "Ingebrekestelling registreren", which records the date the notice was received and who sent it, links the notice to the case and its running decision term, and starts the accruing amount. A notice on a case whose term has not passed SHALL be refused with the reason.

#### Scenario: A notice after the term starts the accrual

- **GIVEN** a case whose decision term ended 3 days ago
- **WHEN** the handler registers a notice received today from the applicant
- **THEN** the notice SHALL be stored and linked to the case
- **AND** an accruing amount SHALL start two weeks after the notice, per Awb 4:17
- @e2e tests/e2e/notice-of-default.spec.ts

#### Scenario: A notice before the term ends is refused

- **GIVEN** a case whose decision term has 10 days left
- **WHEN** the handler registers a notice
- **THEN** the dialog SHALL say the term has not passed and store nothing
- @e2e tests/e2e/notice-of-default.spec.ts

### Requirement: A definitive penalty is prepared for payout from its notice (REQ-ING-CASE-02)

A notice whose accruing amount is definitive SHALL offer "Uitbetaling voorbereiden", which raises the payout with the finance app through the existing payout route, and the notice SHALL show the payout state (prepared, signal sent, paid).

#### Scenario: Preparing the payout sends the signal

- **GIVEN** a notice with a definitive amount of 1.442 euro
- **WHEN** the handler prepares the payout
- **THEN** the payout SHALL be raised with the finance app and the row SHALL read "Betaalsignaal verstuurd"
- @e2e tests/e2e/notice-of-default.spec.ts
