## MODIFIED Requirements

### Requirement: An archivist recomputes a nomination by saying why (REQ-ARCH-11)

The Archiving tab SHALL offer a recomputation to an archivist or an
administrator only, over `POST /archival/objects/{id}/nomination/recompute`. The
reason SHALL be a required field collected before the request is sent, and the
tab SHALL name the person the act will be recorded against. A reader who is
neither archivist nor administrator SHALL see the control disabled with the role
it needs, never hidden. The tab SHALL offer the control once, in the Archiving
card's title row.

#### Scenario: the reason is asked for before the request is sent
@e2e tests/e2e/the-case-archives-through-openregister.spec.ts

- **GIVEN** an archivist on the Archiving tab of a closed case
- **WHEN** they open the recompute control with an empty reason
- **THEN** the confirm SHALL be disabled
- **AND** no request SHALL have been sent

#### Scenario: a handler without the role is told which role it needs

- **GIVEN** a handler who is neither archivist nor administrator
- **WHEN** they open the Archiving tab
- **THEN** the recompute control SHALL be shown and disabled
- **AND** it SHALL name the role required

#### Scenario: the control is offered once

- **GIVEN** any reader on the Archiving tab of a case
- **WHEN** the tab renders
- **THEN** exactly one recompute control SHALL be shown, in the Archiving card's title row
