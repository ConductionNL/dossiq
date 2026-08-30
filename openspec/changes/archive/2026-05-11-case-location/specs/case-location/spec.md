## ADDED Requirements

### Requirement: REQ-CL-1 Location Entity Schema

The system SHALL register a `location` schema in the Procest OpenRegister configuration. The schema SHALL declare the properties `case`, `nummeraanduidingId`, `formattedAddress`, `latitude`, `longitude`, `parcelId`, `accuracyRadius`, `source`, and `label`. The `required` list SHALL be exactly `[case, source]`. The `source` enum SHALL be exactly `[bag, pdok-reverse, gps, free, import]`. The Schema.org type SHALL be `schema:Place`. The schema SHALL be resolvable at runtime through a new `case_location_schema` config key registered in `SettingsService`.

**Feature tier**: V1
**Schema.org type**: `schema:Place`

#### Scenario: Schema is registered on app install

- **GIVEN** the Procest app is installed or updated via the repair step
- **WHEN** `EnsureProcestSchemas::run()` executes
- **THEN** the `location` schema SHALL exist in the Procest register
- **AND** the schema SHALL enforce the required properties `case` and `source`
- **AND** the `source` enum SHALL be exactly `[bag, pdok-reverse, gps, free, import]`

#### Scenario: Schema is resolvable through the settings service

- **WHEN** a service requests `SettingsService::getSchemaId('case_location_schema')`
- **THEN** the configured UUID of the `location` schema SHALL be returned
- **AND** the resolved schema SHALL match `slug = location` in the Procest register

### Requirement: REQ-CL-2 Case ↔ Location Relation (0..N)

The system SHALL support 0..N locations per case. Locations SHALL be linked to their parent case through the `location.case` back-reference; the `case` schema SHALL NOT carry an array of location IDs. Adding, editing, or removing a location SHALL NOT trigger a write to the case object.

**Feature tier**: V1

#### Scenario: Multiple locations attached to a single case

- **GIVEN** a case `zaak-1` of zaaktype "Handhaving" with three locations created (two BAG addresses and one GPS pin)
- **WHEN** `LocationService::listForCase("zaak-1")` is called
- **THEN** all three locations SHALL be returned
- **AND** the `case` object SHALL NOT have been mutated by the creation of any of the three locations

#### Scenario: Case with no locations

- **GIVEN** a newly-created case with no location rows
- **WHEN** `LocationService::listForCase(caseId)` is called
- **THEN** an empty array SHALL be returned (not an error)

### Requirement: REQ-CL-3 PDOK Locatieserver Client

The system SHALL provide a `PdokLocatieserverClient` that wraps the PDOK Locatieserver v3 endpoints `/suggest`, `/lookup`, and `/reverse`. Responses from `/lookup` and `/reverse` SHALL be cached in APCu for 24 hours, keyed on the request input. When an OpenConnector source for PDOK is configured, all outbound requests SHALL be routed through OpenConnector instead of called directly.

**Feature tier**: V1

#### Scenario: Lookup result is cached for 24 hours

- **GIVEN** the client has just executed `/lookup` for `nummeraanduidingId = 0363200000406567`
- **WHEN** a second call for the same identifier arrives within 24 hours
- **THEN** the cached response SHALL be returned without a network call
- **AND** no entry SHALL appear in the OpenConnector egress log for the second call

#### Scenario: OpenConnector proxying is honoured

- **GIVEN** the admin has configured an OpenConnector source for PDOK Locatieserver
- **WHEN** the client executes `/suggest`
- **THEN** the request SHALL be dispatched through the OpenConnector source
- **AND** the public PDOK hostname SHALL NOT appear in the application's outbound HTTP audit

### Requirement: REQ-CL-4 Address Validation on Save

The `LocationService` SHALL validate every saved location according to its declared `source`. The cross-source rules from `design.md` §Validation Rules SHALL be enforced. A `source = bag` row whose `nummeraanduidingId` does not resolve at PDOK Locatieserver `/lookup` SHALL be rejected with HTTP 422 and an error code of `nummeraanduidingId.not-found`. A row that satisfies neither "has nummeraanduidingId" nor "has both latitude and longitude" SHALL be rejected with `coordinates-or-bag.required`.

**Feature tier**: V1

#### Scenario: BAG-source row with unknown nummeraanduiding is rejected

- **GIVEN** a payload `{case, source: "bag", nummeraanduidingId: "9999999999999999"}` for which PDOK `/lookup` returns no match
- **WHEN** `POST /api/locations` is invoked
- **THEN** the response SHALL be HTTP 422 with error code `nummeraanduidingId.not-found`
- **AND** no `location` object SHALL be persisted

#### Scenario: Row without any anchor is rejected

- **GIVEN** a payload `{case, source: "free", label: "veldje achter zwembad"}` with neither coordinates nor address nor BAG identifier
- **WHEN** `POST /api/locations` is invoked
- **THEN** the response SHALL be HTTP 422 with error code `coordinates-or-bag.required`

### Requirement: REQ-CL-5 Reverse Geocoding from Coordinates

When a location is saved with `source = pdok-reverse` and valid `latitude`/`longitude`, the `LocationService` SHALL call PDOK Locatieserver `/reverse` and populate `formattedAddress` from the response. When the best BAG match lies within 25 metres of the input coordinate, the service SHALL also populate `nummeraanduidingId`. The reverse-geocoded fields SHALL come from the service and SHALL NOT be taken from the request body.

**Feature tier**: V1

#### Scenario: Coordinates near a BAG address produce a nummeraanduiding

- **GIVEN** a payload `{case, source: "pdok-reverse", latitude: 52.3702, longitude: 4.8897, formattedAddress: "user-supplied junk"}`
- **AND** PDOK `/reverse` returns the BAG match "Keizersgracht 100, 1015 AA Amsterdam" at distance 8 m with `nummeraanduidingId = 0363200000406567`
- **WHEN** the location is saved
- **THEN** the persisted row's `formattedAddress` SHALL equal "Keizersgracht 100, 1015 AA Amsterdam"
- **AND** the persisted row's `nummeraanduidingId` SHALL equal "0363200000406567"
- **AND** the user-supplied `formattedAddress` SHALL be discarded

#### Scenario: Coordinates beyond match radius leave nummeraanduiding unset

- **GIVEN** a payload `{case, source: "pdok-reverse", latitude: 53.0000, longitude: 4.0000}` in a body of water
- **AND** the nearest BAG match is 1.2 km away
- **WHEN** the location is saved
- **THEN** the persisted row SHALL have `nummeraanduidingId = null`
- **AND** `formattedAddress` MAY be populated with the nearest descriptive label or left null

### Requirement: REQ-CL-6 Case Detail "Locaties" Component

The case detail view SHALL render a "Locaties" tab listing every `location` row for the open case. Each row SHALL show the address (or coordinates when no address is known), the `source` badge, and an accuracy hint when `accuracyRadius` is present. Users with edit rights on the case SHALL be able to add, edit, and remove locations through the tab without leaving the case detail view.

**Feature tier**: V1

#### Scenario: User adds a BAG address via autocomplete

- **GIVEN** the user opens the "Locaties" tab on case `zaak-1` and clicks "Locatie toevoegen"
- **WHEN** the user types "Keizersgracht 100 Amsterdam" and selects the first suggestion
- **THEN** the dialog SHALL pre-fill `formattedAddress`, `nummeraanduidingId`, `latitude`, and `longitude` from the PDOK suggest response
- **AND** clicking "Opslaan" SHALL create the row with `source = bag`
- **AND** the new row SHALL appear in the tab without a full page reload

#### Scenario: User without edit rights sees read-only tab

- **GIVEN** a user with read-only access to case `zaak-1`
- **WHEN** they open the "Locaties" tab
- **THEN** existing locations SHALL be visible
- **AND** the "Locatie toevoegen", "Bewerken", and "Verwijderen" controls SHALL be hidden

### Requirement: REQ-CL-7 Admin CSV Import

The admin settings UI SHALL expose a CSV import flow for bulk-creating locations. The flow SHALL be two-phase: a `preview` phase that parses the file, validates each row, and returns a report without writing; and a `commit` phase that persists only the rows that previewed cleanly. The CSV columns SHALL be `caseIdentifier`, `nummeraanduidingId`, `formattedAddress`, `latitude`, `longitude`, `parcelId`, `accuracyRadius`, `source`, `label`.

**Feature tier**: V1

#### Scenario: Dry-run reports row-level outcomes without writing

- **GIVEN** an admin uploads a 50-row CSV in which 47 rows are valid and 3 reference unknown case identifiers
- **WHEN** the admin clicks "Voorbeeld"
- **THEN** the preview report SHALL show 47 `ok` rows and 3 rows with error code `case-not-found`
- **AND** no `location` object SHALL have been created at this point

#### Scenario: Commit writes only the previewed-clean rows

- **GIVEN** the preview from the previous scenario
- **WHEN** the admin clicks "Importeren"
- **THEN** exactly 47 `location` objects SHALL be created
- **AND** the 3 case-not-found rows SHALL remain in the report as skipped

### Requirement: REQ-CL-8 CSV Export

The admin UI SHALL expose a CSV export of all case locations. The export SHALL use the same column layout as the importer so that an exported file can be re-imported into a fresh environment.

**Feature tier**: V1

#### Scenario: Export contains every persisted location

- **GIVEN** the environment has 1,200 `location` rows across 800 cases
- **WHEN** the admin invokes `GET /api/locations/export?format=csv`
- **THEN** the response SHALL be a `text/csv` body with 1,201 lines (one header + 1,200 rows)
- **AND** the column order SHALL be `caseIdentifier, nummeraanduidingId, formattedAddress, latitude, longitude, parcelId, accuracyRadius, source, label`

#### Scenario: Shapefile export is out of scope

- **GIVEN** an admin requests `GET /api/locations/export?format=shapefile`
- **WHEN** the controller resolves the request
- **THEN** the response SHALL be HTTP 501 with body `{"error": "shapefile-export-not-implemented"}`
- **AND** the response body SHALL reference the follow-up issue tracking V2 shapefile support
