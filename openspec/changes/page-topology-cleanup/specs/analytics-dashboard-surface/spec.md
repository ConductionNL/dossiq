# analytics-dashboard-surface

## ADDED Requirements

### Requirement: Every analytics dashboard is a manifest dashboard page composed of widgets

Each of procest's three analytics surfaces — deadline monitoring, processing time,
and process mining — SHALL be declared as a `type: "dashboard"` page whose
`config.widgets` and `config.layout` compose the surface out of nc-vue leaf
widgets. A dashboard page SHALL NOT delegate its whole body to a single custom
component, and SHALL NOT be declared as `type: "custom"`.

The page header (title, subtitle, period/filter controls) SHALL be supplied by
the dashboard page type, not re-implemented inside a slot component.

#### Scenario: The three analytics pages share one render path

- **GIVEN** the manifest pages `TermijnDashboard`, `Doorlooptijd` and `ProcessMiningDashboard`
- **THEN** each declares `type: "dashboard"`
- **AND** each declares two or more entries in `config.widgets`
- **AND** none declares `type: "custom"` or a top-level `component`

#### Scenario: A dashboard page renders exactly one page heading

- **GIVEN** a user opens any of the three analytics dashboards
- **THEN** the page renders exactly one `<h2>` page heading, supplied by the page type
- **AND** no widget slot component renders its own page-level heading or subtitle

### Requirement: No dashboard page nests a dashboard inside its own widget slot

A `type: "dashboard"` page SHALL NOT declare a widget whose slot component itself
renders a dashboard page host. A single widget occupying the full grid
(`gridWidth: 12`, `gridHeight: 12`) whose slot renders a complete bespoke
dashboard is the dashboard-in-dashboard antipattern and SHALL NOT ship.

#### Scenario: The dashboard-antipattern gate is green

- **GIVEN** the procest repository
- **WHEN** `hydra-gate-dashboard-antipattern` runs
- **THEN** it reports no findings

#### Scenario: No widget occupies the entire grid alone

- **GIVEN** any `type: "dashboard"` page in the manifest
- **THEN** its `config.layout` contains more than one entry, or its single entry does not span the full 12x12 grid
