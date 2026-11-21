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

## ADDED Requirements

### Requirement: An open case shows the archival outcome its type expects (REQ-ARCH-13)

Before a case closes, the Archiving tab SHALL show the appraisal and retention period its case type's result types agree on, or that the outcome depends on the result. It SHALL show whether a legal hold keeps the case for a procedure and whether the case goes to the e-Depot.

#### Scenario: a Woo request in progress
@e2e exclude config pinned by tests/vitest/recordCardHeaderActions.spec.js; the widget behaviour is tested in nextcloud-vue

- **GIVEN** an open Woo request whose result types all destroy after five years
- **WHEN** the handler opens the Archiving tab
- **THEN** it SHALL read Waardering "Vernietigen" and Bewaartermijn "5 jaar na het besluit"
- **AND** "Geen juridische bewaarplicht" and "Niet van toepassing" for the e-Depot
