# case-type-navigation Specification

## Purpose
Model objections, appeals and subsidies as case TYPES rather than standalone navigation areas: the "Cases" group carries one navigation child per case type, resolved from live OpenRegister data via a backend `/api/manifest` delta, and the Cases index offers a map view.

## Requirements

### Requirement: REQ-CTN-001 — One Navigation Child Per Case Type Via /api/manifest Delta

dossiq SHALL expose `GET /api/manifest` (authenticated, `#[NoAdminRequired]`) returning a `mergeStrategy: 'delta'` menu payload that adds one navigation child per `caseType` object the current user may see under the existing `CasesGroup`. Each child SHALL carry `id: 'ct-<uuid>'`, `label: <case-type name>`, `route: 'Cases'` and `query: { caseType: <uuid> }`, sorted deterministically by name. The frontend SHALL consume this delta through `useAppManifest('dossiq', builtManifest, { mergeStrategy: 'delta' })` so the resolved manifest — and thus the navigation — updates reactively when the delta lands.

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

dossiq SHALL NOT present dedicated navigation groups for objections/appeals or subsidies. The `BezwaarBeroepGroup` and `SubsidiesGroup` menu groups SHALL be removed, and the standalone `BezwaarDecisions` and `BezwaarAdviceRequests` workflow pages (page objects, menu entries and routes) SHALL be removed. The `Bezwaren`, `Beroepen` and `Subsidies` index pages SHALL remain routable for deep links and end-to-end tests, but SHALL NOT appear as dedicated menu leaves.

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

### Requirement: REQ-CTN-006 — The picker says how many open cases each case type has

"Case types in my menu" SHALL show, beside every chosen case type and every
case type it offers to add, the number of open cases of that type the user may
see, as "{n} open cases" (nl "{n} open zaken"), as the board
`DqPersoonlijkeInstellingen` draws it.

The numbers SHALL come from one aggregate query per load: a terms facet on the
case's `caseType` over open cases, never one query per case type. A case is
open when it is not at a final status, not at a status hidden from lists and
not a draft. A case opened under an older version of a case type SHALL count
for the version in use. When the counts cannot be read, the picker SHALL show
no number rather than 0.

#### Scenario: Each case type shows its open cases from one query
@e2e exclude The query and the folding are asserted in MenuCaseTypesServiceTest with an ObjectService fake that counts its calls; the e2e instance's numbers move with every other spec's seeds.
- **GIVEN** three offered case types with 19, 7 and 0 open cases
- **WHEN** the user opens Case types in my menu
- **THEN** the rows and options SHALL say "19 open cases", "7 open cases" and "0 open cases"
- **AND** the server SHALL have asked OpenRegister one facet query and no count per case type

#### Scenario: A case on an older version counts for the version in use
@e2e exclude Asserted in MenuCaseTypesServiceTest.
- **GIVEN** a case type whose version 1 was superseded by version 2
- **AND** 3 open cases on version 1 and 2 on version 2
- **WHEN** the counts are read
- **THEN** the case type SHALL show 5 open cases

#### Scenario: An unreadable count shows no number
@e2e exclude The null is asserted in MenuCaseTypesServiceTest and its rendering in tests/vitest/menuCaseTypesSettings.spec.js.
- **GIVEN** OpenRegister cannot answer the facet
- **WHEN** the user opens Case types in my menu
- **THEN** no row and no option SHALL show a number

### Requirement: REQ-CTN-007 — A case type in my menu is named in the reader's language

The case types offered for "My case types", the chosen list and the menu entries
SHALL carry the case type's title as text in the reader's language. Because the
title is translatable and stored as a language map, the title SHALL be resolved to
the reader's language, then to Dutch, then to the first text the map holds. A case
type with no title SHALL be named by its id. A title SHALL never be shown as
"Array".

#### Scenario: A translatable title is read in the reader's language
@e2e exclude the language map is resolved server side and the instance seeds Dutch titles only; proven by tests/Unit/Service/MenuCaseTypesServiceTest.php::testATranslatableTitleIsReadInTheReadersLanguage

- **GIVEN** a case type whose title is `{"nl": "Kapvergunning", "en": "Tree felling permit"}`
- **WHEN** a reader whose language is English opens the case type picker
- **THEN** the case type SHALL be named "Tree felling permit"

#### Scenario: A title without the reader's language falls back to Dutch
@e2e exclude the language map is resolved server side; proven by tests/Unit/Service/MenuCaseTypesServiceTest.php::testATranslatableTitleIsReadInTheReadersLanguage

- **GIVEN** a case type whose title is `{"nl": "Woo-verzoek"}`
- **WHEN** a reader whose language is English opens the case type picker
- **THEN** the case type SHALL be named "Woo-verzoek"
