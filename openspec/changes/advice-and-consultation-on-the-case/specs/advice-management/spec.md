## ADDED Requirements

### Requirement: A handler asks for advice from the case (REQ-ADV-CASE-01)

The case page SHALL hold the card board DqZaakBesluiten draws on the Decisions tab: the internal and external advice requested on the case, each with its advisor, when it was asked, its deadline or the date the answer came in, and its state. Ask for advice in the card's title row SHALL record the advisor, internal or external, the subject, the questions and a deadline, linked to the case. An open request SHALL offer Remind. The per-case advice routes SHALL live under `/api/cases/{id}/advice-requests`, with the old `/api/vth/` address answering for one release. An advice request on the personal queue SHALL open its case on the tab with this card.

#### Scenario: Asking for advice with a deadline

- **GIVEN** a case in status "in behandeling"
- **WHEN** the handler asks "Welstand" for advice with deadline 20 November
- **THEN** an advice request SHALL be linked to the case with state "requested"
- **AND** the case's advice card SHALL show it with "20 nov." and "Waiting for answer"
- @e2e tests/e2e/advice-on-the-case.spec.ts

#### Scenario: The answer shows on the case

- **GIVEN** an advice request on a case
- **WHEN** the answer comes in
- **THEN** the case's advice card SHALL show the request as received
- @e2e tests/e2e/advice-on-the-case.spec.ts

#### Scenario: An open request can be reminded

- **GIVEN** an open advice request
- **WHEN** the handler chooses Remind
- **THEN** a reminder SHALL be sent to the advisor
- @e2e tests/e2e/advice-on-the-case.spec.ts
