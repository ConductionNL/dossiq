# case-type-navigation Specification

**Status:** proposed
**Scope:** procest

## Purpose

Model objections, appeals and subsidies as case TYPES rather than standalone navigation areas: the "Cases" group carries one navigation child per case type, resolved from live OpenRegister data via a backend `/api/manifest` delta, and the Cases index offers a map view.

## ADDED Requirements

### Requirement: REQ-CTN-001 — One Navigation Child Per Case Type Via /api/manifest Delta

procest SHALL expose `GET /api/manifest` (authenticated, `#[NoAdminRequired]`) returning a `mergeStrategy: 'delta'` menu payload that adds one navigation child per `caseType` object the current user may see under the existing `CasesGroup`. Each child SHALL carry `id: 'ct-<uuid>'`, `label: <case-type name>`, `route: 'Cases'` and `query: { caseType: <uuid> }`, sorted deterministically by name. The frontend SHALL consume this delta through `useAppManifest('procest', builtManifest, { mergeStrategy: 'delta' })` so the resolved manifest — and thus the navigation — updates reactively when the delta lands.

#### Scenario: Case types appear as Cases children

- **GIVEN** the user may see two case types "Aanvraag" and "Bezwaar"
- **WHEN** the app shell loads and fetches `/api/manifest`
- **THEN** the `CasesGroup` menu group SHALL gain two children `ct-<uuid>` labelled "Aanvraag" and "Bezwaar"
- **AND** each child SHALL navigate to the `Cases` route with `query.caseType` set to its case-type uuid
- **AND** the children SHALL be ordered by name

#### Scenario: Delta never breaks the shell

- **GIVEN** OpenRegister is unavailable, the register/schema is unconfigured, the user is anonymous, or no case types exist
- **WHEN** the app shell fetches `/api/manifest`
- **THEN** the endpoint SHALL return a no-op delta `{ "menu": [] }`
- **AND** the app navigation SHALL render from the built manifest unchanged

### Requirement: REQ-CTN-002 — Objections, Appeals And Subsidies Have No Dedicated Menu Group

procest SHALL NOT present dedicated navigation groups for objections/appeals or subsidies. The `BezwaarBeroepGroup` and `SubsidiesGroup` menu groups SHALL be removed, and the standalone `BezwaarDecisions` and `BezwaarAdviceRequests` workflow pages (page objects, menu entries and routes) SHALL be removed. The `Bezwaren`, `Beroepen` and `Subsidies` index pages SHALL remain routable for deep links and end-to-end tests, but SHALL NOT appear as dedicated menu leaves.

#### Scenario: No dedicated objection/appeal/subsidy menu group

- **GIVEN** the app shell has rendered its navigation
- **WHEN** an administrator inspects the menu
- **THEN** there SHALL be no "Objections & Appeals" or "Subsidies" menu group
- **AND** there SHALL be no "Objection decisions" or "Committee advice" menu item

#### Scenario: Retired index pages stay deep-linkable

- **GIVEN** the objection/appeal/subsidy menu leaves are gone
- **WHEN** a user navigates directly to `/bezwaren`, `/beroepen` or the subsidy index route
- **THEN** the index page SHALL still render (route retained for deep links)

### Requirement: REQ-CTN-003 — Cases Index Offers A Map View

The `Cases` index page SHALL offer a map view alongside table and cards (`viewModes: ["table","cards","map"]`), plotting the current filtered case rows on a map using the case `geometry` GeoJSON (`mapConfig` with `geoField: "geometry"`, `popupField: "title"`). A marker click SHALL behave like a case row click. The standalone `CaseMap` menu leaf SHALL be removed while its `/map` route stays reachable for deep links.

#### Scenario: Map is a Cases view mode

- **GIVEN** the user opens the Cases index
- **WHEN** the view-mode toggle is shown
- **THEN** a "Map" segment SHALL be offered next to Table and Cards
- **AND** selecting it SHALL plot the filtered cases on a map by their `geometry`

#### Scenario: Standalone Case Map menu retired

- **GIVEN** the Cases index now covers the map surface
- **WHEN** an administrator inspects the menu
- **THEN** there SHALL be no standalone "Map" menu leaf
- **AND** the `/map` route SHALL still be reachable by deep link
