## ADDED Requirements

### Requirement: A resident may propose a change to what they supplied (REQ-PORTAL-015)
The contribution MUST declare a `propose-change` action on `case`, scoped by
`portalSubject`, whose `proposable` list holds only `title` and
`description`, and `mijnZaken` MUST name it in its row actions. The case page
MUST place portaliq's proposal queue for the case.

#### Scenario: A resident proposes a better subject line
- **GIVEN** a resident viewing their case "Lantaarnpaal kapot" in the portal
- **WHEN** they propose the title "Lantaarnpaal kapot bij Dorpsstraat 4"
- **THEN** a proposal MUST be queued for that case with the old and the new title
- **AND** the handler MUST see it under "Voorgestelde wijzigingen" on the case page and accept it, after which the case carries the new title

#### Scenario: A status cannot be proposed
- **GIVEN** the same resident
- **WHEN** a proposal names the field `status`
- **THEN** portaliq MUST refuse it, because `status` is not in the `proposable` list
