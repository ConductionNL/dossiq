## ADDED Requirements

### Requirement: Reusable Map Component Wrapper (REQ-MC-1)

A single Vue component `MapComponent` SHALL be provided at `src/components/map/MapComponent.vue` as a Procest-side wrapper around `CnMapWidget` from `@conduction/nextcloud-vue` (≥ beta.30). All in-app map embeddings (case detail map tab, dashboard map widget, public case page) SHALL render through this component rather than instantiating `CnMapWidget` or Leaflet directly.

**Feature tier**: V1
**Standards**: ADR-008 (manifest-driven views), `@conduction/nextcloud-vue` `CnMapWidget`

#### Scenario: Component exists and wraps the lib widget

- **GIVEN** the Procest source tree on the `chore/spec-map-component` branch's implementation
- **WHEN** the file `src/components/map/MapComponent.vue` is opened
- **THEN** it SHALL `import { CnMapWidget } from '@conduction/nextcloud-vue'`
- **AND** its template SHALL render exactly one `<CnMapWidget>` root element
- **AND** `package.json` peer / dependency on `@conduction/nextcloud-vue` SHALL be `^beta.30` or higher

#### Scenario: All embeddings consume MapComponent

- **GIVEN** the files `src/views/cases/components/CaseMapTab.vue`, `src/views/dashboard/CaseMapWidget.vue`, and `src/views/public/PublicCaseView.vue`
- **WHEN** each file is searched for direct `CnMapWidget` or `L.map(` (Leaflet) usage
- **THEN** zero matches SHALL be found
- **AND** each file SHALL contain at least one `<MapComponent` template reference

---

### Requirement: Component Prop API (REQ-MC-2)

`MapComponent` SHALL expose the following props with defaults: `locations` (array, default `[]`), `center` (`{lat, lon}`, default `{lat: 52.1326, lon: 5.2913}`), `zoom` (number, default `7`), `interactive` (boolean, default `true`), `markerFormatter` (string, default `"caseMarkerFormatter"`), `tileLayer` (string, default `"pdok-brt"`), `clustering` (boolean, default `true`), `height` (string, default `"400px"`).

**Feature tier**: V1

#### Scenario: Default props produce a Netherlands-centred map

- **GIVEN** `<MapComponent />` is mounted with no props
- **WHEN** the component renders
- **THEN** the underlying `CnMapWidget` SHALL receive `center.lat: 52.1326`, `center.lon: 5.2913`, `zoom: 7`
- **AND** the tile layer SHALL be `"pdok-brt"`
- **AND** clustering SHALL be enabled
- **AND** the rendered container SHALL have CSS `height: 400px`

#### Scenario: Locations prop drives markers via formatter

- **GIVEN** `<MapComponent :locations="[{ id: 'c1', geometry: {type:'Point', coordinates:[4.87, 52.36]}, title:'Test', status:'open' }]" />`
- **WHEN** the component mounts and the `caseMarkerFormatter` is registered in `app.$mapFormatters`
- **THEN** exactly one marker SHALL be rendered on the map
- **AND** the marker position SHALL be `{lat: 52.36, lon: 4.87}` (±0.0001)

---

### Requirement: Component Events (REQ-MC-3)

`MapComponent` SHALL emit `marker-click`, `viewport-change` (debounced 200 ms), and `ready` events with the payloads defined in `design.md`.

**Feature tier**: V1

#### Scenario: marker-click payload contains marker and original location

- **GIVEN** `MapComponent` is mounted with `locations: [L1]` where `L1.id === "c1"`
- **WHEN** the user clicks the marker for `L1`
- **THEN** a `marker-click` event SHALL be emitted exactly once
- **AND** the event payload SHALL contain `marker` (formatter output: `lat`, `lon`, `color`, `icon`, `popup`, `onClick`)
- **AND** the event payload SHALL contain `location` referentially equal to `L1` (the original input object)

#### Scenario: viewport-change is debounced

- **GIVEN** `MapComponent` is mounted with `interactive: true`
- **WHEN** the user pans the map continuously for 1000 ms
- **THEN** `viewport-change` SHALL be emitted at most 6 times (debounced at 200 ms intervals)
- **AND** the final emission's `center` SHALL match the final map centre (±0.0001 lat/lon)
- **AND** the payload SHALL include `bbox` as `[west, south, east, north]`

#### Scenario: ready event exposes the map instance

- **GIVEN** `MapComponent` mounts
- **WHEN** the underlying Leaflet map instance is constructed
- **THEN** the `ready` event SHALL be emitted exactly once
- **AND** the payload SHALL be `{ map }` where `map` is a Leaflet `L.Map` instance

---

### Requirement: Interactive Mode Toggle (REQ-MC-4)

When `interactive: false`, `MapComponent` SHALL disable map dragging, scroll-wheel zoom, double-click zoom, keyboard navigation, and SHALL hide zoom controls. The container `role` SHALL switch from `"application"` to `"img"`.

**Feature tier**: V1
**Standards**: WCAG AA

#### Scenario: Read-only mode disables all controls

- **GIVEN** `<MapComponent :interactive="false" />`
- **WHEN** the component renders
- **THEN** the Leaflet map SHALL be created with `dragging: false`, `scrollWheelZoom: false`, `doubleClickZoom: false`, `boxZoom: false`, `keyboard: false`, `zoomControl: false`
- **AND** no zoom-control buttons SHALL be present in the rendered DOM
- **AND** the map container SHALL have `role="img"`

#### Scenario: Interactive mode enables full Leaflet defaults

- **GIVEN** `<MapComponent :interactive="true" />`
- **WHEN** the component renders
- **THEN** the Leaflet map SHALL be created with default interaction flags (`dragging: true`, `keyboard: true`, `zoomControl: true`)
- **AND** the map container SHALL have `role="application"`

---

### Requirement: NL Design System & Accessibility (REQ-MC-5)

`MapComponent` SHALL use NL Design System CSS variable tokens for all colors (no hardcoded hex anywhere in the component) and SHALL meet WCAG AA: keyboard navigation, accessible labels, focus management.

**Feature tier**: V1
**Standards**: NL Design System color tokens, WCAG AA, i18n (Dutch + English minimum)

#### Scenario: No hardcoded hex values in component

- **GIVEN** the file `src/components/map/MapComponent.vue`
- **WHEN** searched with regex `#[0-9A-Fa-f]{3,8}\b` excluding comments
- **THEN** zero matches SHALL be found

#### Scenario: Aria label switches with interactive prop

- **GIVEN** the user locale is Dutch (`nl`)
- **WHEN** `<MapComponent :interactive="true" />` mounts
- **THEN** the map container SHALL have `aria-label="Kaart met zaaklocaties"`
- **AND** when `interactive: false` is set
- **THEN** the map container SHALL have `aria-label="Kaart met zaaklocaties (alleen-lezen)"`
- **AND** equivalent English strings SHALL be available via the i18n bundle for locale `en`

#### Scenario: Keyboard navigation in interactive mode

- **GIVEN** `<MapComponent :interactive="true" />` is mounted and focused
- **WHEN** the user presses `ArrowRight`
- **THEN** the map centre `lon` SHALL increase
- **AND** when the user presses `+`
- **THEN** the map `zoom` SHALL increase by 1
- **AND** when the user presses `Tab`
- **THEN** focus SHALL move to the first marker (if any) before leaving the component

---

### Requirement: Registration in customComponents (REQ-MC-6)

`MapComponent` SHALL be registered in the Procest `customComponents` registry in `src/main.js` under the name `"MapComponent"` so that manifest entries and other Procest components MAY reference it by name without explicit imports.

**Feature tier**: V1
**Standards**: ADR-008 (manifest-driven views)

#### Scenario: Component registered by name

- **GIVEN** the file `src/main.js`
- **WHEN** the file is opened
- **THEN** it SHALL import `MapComponent` from `./components/map/MapComponent.vue`
- **AND** it SHALL register the component in `app.config.globalProperties.$customComponents` (or equivalent registry) under the exact key `"MapComponent"`

#### Scenario: Manifest entry can reference component by name

- **GIVEN** a hypothetical manifest page entry `{ "id": "Demo", "route": "/demo", "type": "custom", "component": "MapComponent" }`
- **WHEN** the app shell resolves the route
- **THEN** the registered `MapComponent` SHALL be rendered
- **AND** no console error SHALL be emitted about an unknown component name
