## ADDED Requirements

### Requirement: The decision stage of a domain case shows the What now? card

The status a domain case type uses for weighing and deciding SHALL carry the
`review` role, and a case type SHALL NOT give one role to two statuses.

#### Scenario: An objection after the committee's advice
- GIVEN an objection case in status "Advies uitgebracht"
- WHEN the case page opens
- THEN the What now? card for the review stage shows

### Requirement: The LHS advice page shows the next act

The LHS advice page SHALL show a What now? card for the intervention the
advice settles on.

#### Scenario: A penalty order
- GIVEN an advice whose final intervention is a penalty order
- WHEN the page opens
- THEN the card asks for the pre-announcement and the two-week period for a view
