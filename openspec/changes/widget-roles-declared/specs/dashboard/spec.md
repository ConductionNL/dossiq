## ADDED Requirements

### Requirement: Every dossiq widget declares who may see it (REQ-DASH-01)

Every widget dossiq declares in `src/manifest.json` SHALL carry the roles
that may see it. A structural test SHALL fail when a dossiq widget
declares no roles and carries no reason-bearing allowlist entry, and SHALL
fail when an allowlisted widget gains a declaration without its entry being
removed.

#### Scenario: a widget without a declaration fails the build

- **GIVEN** a new widget declared with no roles
- **WHEN** the structural test runs
- **THEN** it SHALL fail, naming the widget and its page

#### Scenario: a widget for everyone says so

- **GIVEN** a widget every reader may see
- **WHEN** it is allowlisted with that reason
- **THEN** the structural test SHALL pass

### Requirement: A reader who may not see a widget receives none of its data (REQ-DASH-02)

The check SHALL be on the widget's data read, not in the browser. A reader
holding none of a widget's declared roles SHALL receive none of its data,
whatever the page requests. The page SHALL be laid out without the widget
and SHALL NOT render it as an empty box.

#### Scenario: a case handler does not receive the financial tile
@e2e tests/e2e/widget-roles-declared.spec.ts

- **GIVEN** a widget declared for the financial role
- **WHEN** a case handler opens the page
- **THEN** the widget SHALL NOT be rendered
- **AND** the page payload SHALL carry none of its data

#### Scenario: the rest of the page still works
@e2e tests/e2e/widget-roles-declared.spec.ts

- **GIVEN** the same reader and page
- **WHEN** they open it
- **THEN** every widget they do hold a role for SHALL be rendered

#### Scenario: no empty box
@e2e tests/e2e/widget-roles-declared.spec.ts

- **GIVEN** a reader who may not see one widget on a page
- **WHEN** they read the page
- **THEN** no placeholder SHALL be shown where it would have been

#### Scenario: asking for it directly still refuses

- **GIVEN** a reader holding none of a widget's roles
- **WHEN** they call the widget's data endpoint directly
- **THEN** it SHALL refuse

### Requirement: An unresolvable role hides the widget (REQ-DASH-03)

A widget declaring a role that cannot be resolved SHALL NOT be rendered,
and the failure SHALL be reported to an administrator. It SHALL NOT default
to visible.

#### Scenario: a renamed role does not open a figure to everyone
@e2e tests/e2e/widget-roles-declared.spec.ts

- **GIVEN** a widget declaring a role that no longer exists
- **WHEN** any reader opens the page
- **THEN** the widget SHALL NOT be rendered

#### Scenario: an administrator is told

- **GIVEN** a widget with an unresolvable role
- **WHEN** an administrator reads the configuration report
- **THEN** it SHALL name the widget and the missing role
