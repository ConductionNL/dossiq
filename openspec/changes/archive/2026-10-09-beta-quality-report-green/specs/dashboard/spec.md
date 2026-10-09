## MODIFIED Requirements

### Requirement: Every dossiq widget declares who may see it (REQ-WRD-01)

Every widget dossiq declares in `src/manifest.json` SHALL carry the roles
that may see it. A structural test SHALL fail when a dossiq widget
declares no roles and carries no reason-bearing allowlist entry, and SHALL
fail when an allowlisted widget gains a declaration without its entry being
removed. A dashboard widget definition SHALL be read from `config.widgets`,
from `widgets`, and from the `widgets` of every view in `config.views`. A
placement (`widgetKey` with grid coordinates) SHALL NOT count as a
definition.

#### Scenario: a widget without a declaration fails the build

- **GIVEN** a new widget declared with no roles
- **WHEN** the structural test runs
- **THEN** it SHALL fail, naming the widget and its page

#### Scenario: a widget for everyone says so

- **GIVEN** a widget every reader may see
- **WHEN** it is allowlisted with that reason
- **THEN** the structural test SHALL pass

#### Scenario: A widget inside a view is checked

- GIVEN a dashboard page whose view "team" declares a widget `team-sla`
  with `roles: ["dossiq-teamleider"]`
- WHEN the widget roles are read
- THEN `team-sla` is among the dashboard widgets
- AND a reader without `dossiq-teamleider` gets the verdict `hidden`

#### Scenario: Moving widgets into views leaves the allowlist true

- GIVEN the landing page shows `my-work` and `deadlines` in its "mine" view
- WHEN the allowlist test asks which allowlisted widgets are gone
- THEN neither is reported as gone
