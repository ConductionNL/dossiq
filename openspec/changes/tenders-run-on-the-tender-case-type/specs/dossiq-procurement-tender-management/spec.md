## ADDED Requirements

### Requirement: A tender is worked end to end on its case page (REQ-TND-CASE-01)

A case of the tender case type SHALL show its lots, questions and published answers, received bids, the evaluation per bid with the score the configured formula gives, and the award, and SHALL offer the actions to publish an answer, register a bid before the closing date, score a bid and propose the award. A bid registered after the closing date SHALL be refused. The award SHALL start the standstill term and SHALL NOT be final before it ends.

#### Scenario: A late bid is refused

- **GIVEN** a tender case whose closing date was yesterday
- **WHEN** a buyer registers a bid
- **THEN** the bid SHALL be refused with the closing date in the message
- @e2e tests/e2e/tender-case.spec.ts

#### Scenario: The formula ranks the bids

- **GIVEN** three bids scored on quality (60) and price (40) with the price-quality formula
- **WHEN** the buyer opens the evaluation
- **THEN** the bids SHALL be listed by the formula's total, highest first
- @e2e tests/e2e/tender-case.spec.ts

#### Scenario: The award waits for the standstill

- **GIVEN** a proposed award
- **WHEN** the buyer tries to make it final before the 20 days of standstill end
- **THEN** the transition SHALL be refused naming the date it ends
- @e2e tests/e2e/tender-case.spec.ts
