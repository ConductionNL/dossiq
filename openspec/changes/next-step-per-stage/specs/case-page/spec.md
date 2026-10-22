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

### Requirement: A case type keeps its own What now? texts

A case type MAY declare `nextStep` with its own stages (title, checklist and what follows per stage). The case page
SHALL read them through `nextStep.stagesFrom` and SHALL keep its own stages for a case type that declares none.

#### Scenario: An objection with its own wording
- GIVEN the Bezwaar case type declares its own text for the review stage
- WHEN an objection in that stage opens
- THEN the What now? card reads the case type's text, and a permit case still reads the page's
