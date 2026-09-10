# Hours on a case are placed, not queried (delta)

## Purpose

Hours booked against a case are rendered by humaniq's `humaniq-hours` integration
leaf, placed on the case detail page. Dossiq's manifest holds no query against
humaniq's register. Consumption only (ADR-022); the leaf's behaviour belongs to
humaniq.

## ADDED Requirements

### Requirement: REQ-HRS-001, the hours surface on a case is a leaf placement

The `case-kpis-hours` widget on the `CaseDetail` page in `src/manifest.json` MUST
be declared as `{"type": "integration", "integrationId": "humaniq-hours"}`. It
MUST NOT declare `content.entries`, a `register`, a `schema` or a `filter`, and
no part of `src/manifest.json` MAY name the `humaniq` register at all. The
`open-form` header action `log-hours` was the last one that did, and it goes with
the query: the leaf's own booking dialog writes the same time entry, seeded from
the same case, beside the total those hours land in.

#### Scenario: Hours render on a case with humaniq installed

- **WHEN** a caseworker opens a case detail on an install where humaniq is enabled
- **THEN** the `humaniq-hours` leaf SHALL render in the widget's cell, showing the
  hours booked against that case and the bookings that explain the total

#### Scenario: The surface is absent when humaniq is

- **WHEN** dossiq is installed and humaniq is not
- **THEN** no `humaniq-hours` leaf SHALL be registered, so the case detail SHALL
  render no hours surface at all
- **AND** it SHALL NOT render `0`, which is what a case with no hours booked
  renders (ADR-113)

#### Scenario: No cross-app register query survives

- **WHEN** `src/manifest.json` is searched for `"register": "humaniq"`
- **THEN** there SHALL be no match at all, in a widget or anywhere else

### Requirement: REQ-HRS-002, the host object context is derived, never declared

The placement MUST NOT declare `domainObjectType` or `domainObjectRef`. The host
forwards `register`, `schema` and `objectId` to a mounted leaf, and the
`CaseDetail` page config carries `register: "dossiq"` and `schema: "case"`, so
the leaf derives the `<app>:<schema>` literal itself.

#### Scenario: The leaf reads the right case

- **WHEN** the leaf is mounted on the detail page of a case with uuid U
- **THEN** it SHALL filter time entries on `domainObjectType` = `dossiq:case` and
  `domainObjectRef` = U
- **AND** it SHALL derive that literal from the page config rather than read it
  from the widget definition, so a page whose register or schema is renamed cannot
  keep pointing at the old one

### Requirement: REQ-HRS-003, the widget keeps its identity and its cell

The widget id MUST remain `case-kpis-hours`, and the `layout` entry naming it
MUST be unchanged.

#### Scenario: The layout entry still resolves

- **WHEN** the `CaseDetail` layout is read after this change
- **THEN** the entry with `widgetId: "case-kpis-hours"` SHALL resolve to the
  integration widget, at `gridX` 8, `gridY` 8, width 4, height 2
- **AND** no other widget's position SHALL have moved

### Requirement: REQ-HRS-004, absence is handled by the leaf, not by requiredApp

The placement MUST NOT declare `requiredApp`. A leaf whose app is absent is never
registered, so there is nothing to gate.

#### Scenario: No requiredApp anywhere on an integration widget

- **WHEN** every `"type": "integration"` widget in `src/manifest.json` is read
- **THEN** none SHALL declare `requiredApp`
