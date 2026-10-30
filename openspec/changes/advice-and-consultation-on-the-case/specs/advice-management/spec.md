## ADDED Requirements

### Requirement: A handler asks for advice or starts a consultation from the case (REQ-ADV-CASE-01)

The case page SHALL offer "Advies vragen" and "Consultatie starten". Each SHALL record the adviser or consulted body, the question and a deadline, link the request to the case and notify the adviser. The case page SHALL list every request with its state, deadline and answer.

#### Scenario: Asking for advice with a deadline

- **GIVEN** a case in status "in behandeling"
- **WHEN** the handler asks the department "Welstand" for advice with deadline 20 November
- **THEN** an advice request SHALL be linked to the case with state "gevraagd"
- **AND** the case's advice widget SHALL show it with "20 nov."
- @e2e tests/e2e/advice-on-the-case.spec.ts

#### Scenario: The answer shows on the case

- **GIVEN** an advice request on a case
- **WHEN** the adviser submits the answer
- **THEN** the case's advice widget SHALL show the answer and state "gegeven"
- @e2e tests/e2e/advice-on-the-case.spec.ts

#### Scenario: An overdue request can be reminded

- **GIVEN** an advice request past its deadline
- **WHEN** the handler chooses "Herinneren"
- **THEN** a reminder SHALL be sent and recorded on the request
- @e2e tests/e2e/advice-on-the-case.spec.ts
