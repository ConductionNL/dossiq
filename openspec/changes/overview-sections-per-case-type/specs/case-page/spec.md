## ADDED Requirements

### Requirement: A case type declares its Overview cards
A case type MAY list `overviewSections`. A case of that type SHALL show one card per section on the Overview tab. A row SHALL read the named case property, then the case field, and SHALL show a dash when neither holds a text or a number. A case type without sections SHALL keep its Overview as it was.

#### Scenario: A complaint
- GIVEN a case of type Klacht behandeling with a hearing date in its properties
- WHEN the Overview tab opens
- THEN the Hoorgesprek card shows that date, and the priority and locations cards are left out

#### Scenario: A case type without sections
- GIVEN a case type with no `overviewSections`
- WHEN the Overview tab opens
- THEN no section card is drawn

### Requirement: A grant passes through its advances
The Subsidie case type SHALL hold the status Voorschotten between In behandeling and Beschikt, reachable from In behandeling and leading to Beschikt. Beschikt SHALL stay a closed status.

#### Scenario: A granted case with advances
- GIVEN a grant in In behandeling
- WHEN the handler starts the advances
- THEN the case stands at Voorschotten and can still move to Beschikt
