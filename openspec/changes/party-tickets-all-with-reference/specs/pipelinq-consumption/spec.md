# pipelinq-consumption Delta

## ADDED Requirements

### Requirement: A party's Tickets card lists its handled tickets too

A party's Tickets card SHALL list the party's open and handled tickets. Each row SHALL read the ticket's reference, its
type and its handler's name. A handled ticket SHALL show "Handled" with its done date.

#### Scenario: DqOrganisatie

- **GIVEN** Buurtvereniging Oost with one ticket in progress and two resolved
- **THEN** the Tickets card lists all three, the resolved ones green with "Klaar <date>"
