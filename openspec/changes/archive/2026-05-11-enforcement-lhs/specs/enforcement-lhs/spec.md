## ADDED Requirements

### Requirement: REQ-LHS-1 — 3-D LHS matrix schema

The system SHALL register an `lhsMatrix` schema in the Procest OpenRegister configuration that models the Landelijke Handhavingsstrategie as a versioned 3-dimensional matrix indexed by `ernst`, `gedrag`, and `actorType`. The schema SHALL declare the properties `name`, `version`, `active`, `ernstAxis`, `gedragAxis`, `actorTypeAxis`, `cells`, and `auditTrail`. The required list SHALL be exactly `[name, version, active, ernstAxis, gedragAxis, actorTypeAxis, cells]`. Each cell SHALL carry `ernst`, `gedrag`, `actorType`, `interventie`, and an optional `note`. The `interventie` enum SHALL be exactly `[waarschuwing, herstelactie, last_onder_dwangsom, last_plus_pv, bestuursdwang, pv_plus_bestuursdwang]`.

**Feature tier**: V1
**Schema.org type**: `schema:DecisionMatrix` (custom subtype of `schema:Intangible`)
**ZGW mapping**: Custom extension (no ZGW equivalent)
**Standards**: Landelijke Handhavingsstrategie Omgevingsrecht (IPO/VNG, 2024)

#### Scenario: Schema is registered after app install

- **GIVEN** the Procest app is freshly installed
- **WHEN** the repair step runs `ConfigurationService::importFromApp()`
- **THEN** the `lhsMatrix` schema SHALL exist in the Procest register
- **AND** the schema SHALL enforce the required list `[name, version, active, ernstAxis, gedragAxis, actorTypeAxis, cells]`
- **AND** the `interventie` enum on cells SHALL be exactly the six values listed above

#### Scenario: Invalid interventie rejected

- **GIVEN** an administrator attempts to save an `lhsMatrix` whose `cells[0].interventie = "boete"` (not in the enum)
- **WHEN** the save is validated against the schema
- **THEN** OpenRegister SHALL reject the object with a schema validation error
- **AND** no `lhsMatrix` SHALL be persisted

### Requirement: REQ-LHS-2 — Default national LHS matrix seed

The system SHALL seed exactly one `lhsMatrix` with `version = 1` and `active = true` as part of the VTH case-type seed data. The seeded matrix SHALL contain the three axes (`ernst`, `gedrag`, `actorType`) and a dense `cells` array of length 48 (3 × 4 × 4) matching the national IPO/VNG reference. The seed step SHALL be idempotent: re-running it SHALL NOT create a second `lhsMatrix` record.

**Feature tier**: V1

#### Scenario: Default matrix created on VTH seed

- **GIVEN** a tenant with no existing `lhsMatrix` records
- **WHEN** the VTH seed step runs
- **THEN** exactly one `lhsMatrix` record SHALL be created with `name = "Landelijke Handhavingsstrategie 2024"`, `version = 1`, `active = true`
- **AND** `cells.length` SHALL equal 48
- **AND** every (ernst, gedrag, actorType) triple from the three axes SHALL appear exactly once in `cells`

#### Scenario: Seed is idempotent

- **GIVEN** the seed step has already run once and an `active = true` `lhsMatrix` exists
- **WHEN** the seed step runs again
- **THEN** no new `lhsMatrix` record SHALL be created
- **AND** the existing record's `cells` SHALL remain unchanged

### Requirement: REQ-LHS-3 — Sanction recommendation service

The system SHALL provide a `SanctionRecommendationService` with a public `recommend(caseId, ernst, gedrag, actorType)` method that looks up the matching cell in the `active = true` `lhsMatrix` and returns a persisted `sanctionRecommendation` object. The service SHALL derive the acting user UID from `IUserSession` and SHALL NOT trust any UID supplied in the request body. The lookup SHALL be deterministic: identical inputs against the same matrix version SHALL always produce the same `recommendedInterventie`.

**Feature tier**: V1

#### Scenario: Recommend looks up matrix cell

- **GIVEN** the active matrix has a cell `{ernst: aanzienlijk, gedrag: onverschillig, actorType: bedrijf, interventie: last_onder_dwangsom}`
- **WHEN** an inspector calls `recommend(caseId, "aanzienlijk", "onverschillig", "bedrijf")`
- **THEN** the service SHALL return a `sanctionRecommendation` with `recommendedInterventie = "last_onder_dwangsom"`
- **AND** the record's `matrixVersion` SHALL equal the active matrix version
- **AND** `recommendedBy` SHALL equal the authenticated user's UID
- **AND** the record SHALL be persisted in OpenRegister

#### Scenario: Identity is server-derived

- **GIVEN** authenticated user `k.vermeulen` posts a recommend request whose body sets `recommendedBy = "p.janssen"`
- **WHEN** the service handles the request
- **THEN** the persisted record SHALL store `recommendedBy = "k.vermeulen"`
- **AND** the body-supplied UID SHALL be silently ignored

### Requirement: REQ-LHS-4 — Sanction recommendation schema

The system SHALL register a `sanctionRecommendation` schema with properties `case`, `ernst`, `gedrag`, `actorType`, `matrixVersion`, `recommendedInterventie`, `appliedInterventie`, `override`, `overrideJustification`, and `recommendedBy`. The required list SHALL be exactly `[case, ernst, gedrag, actorType, matrixVersion, recommendedInterventie, recommendedBy]`. When `override = true`, `overrideJustification` SHALL be present and SHALL contain at least 20 non-whitespace characters.

**Feature tier**: V1
**Schema.org type**: `schema:Recommendation`

#### Scenario: Override without justification rejected

- **GIVEN** an inspector attempts to save a `sanctionRecommendation` with `override = true` and `overrideJustification = "ok"` (3 characters)
- **WHEN** the save is validated
- **THEN** the save SHALL be rejected with HTTP 422 and Dutch message `Motivatie afwijking moet minimaal 20 tekens bevatten`
- **AND** no record SHALL be persisted

#### Scenario: Non-override does not require justification

- **GIVEN** a saved `sanctionRecommendation` with `recommendedInterventie = appliedInterventie = "waarschuwing"`
- **WHEN** the record is created
- **THEN** `override` SHALL default to `false`
- **AND** `overrideJustification` MAY be absent

### Requirement: REQ-LHS-5 — Inspector decision UI with matrix preview

The system SHALL render an inspector decision UI in step 1 of the enforcement wizard that exposes three axis selectors (ernst, gedrag, actorType) and a three-panel matrix preview where each panel corresponds to one actor-type slice. Selecting all three axes SHALL immediately display the recommendation card containing the recommended interventie, the matrix name + version, and the per-cell `note` if present.

**Feature tier**: V1

#### Scenario: Recommendation card updates on axis change

- **GIVEN** an inspector has opened step 1 of the enforcement wizard for a case
- **WHEN** the inspector selects `ernst = "ernstig"`, `gedrag = "calculerend"`, `actorType = "bedrijf"`
- **THEN** the recommendation card SHALL display the interventie returned by `SanctionRecommendationService::recommend()` for that triple
- **AND** the matrix-preview cell at that coordinate SHALL be highlighted in the `bedrijf` panel
- **AND** the matrix `name` and `version` SHALL be visible on the card

#### Scenario: Actor type pre-fills from case subject

- **GIVEN** a case whose subject is classified as a bedrijf (KvK-bound legal entity)
- **WHEN** the inspector opens step 1
- **THEN** the `actorType` selector SHALL be pre-set to `bedrijf`
- **AND** the inspector SHALL still be able to change it

### Requirement: REQ-LHS-6 — Override with mandatory justification

The system SHALL allow an inspector to override the recommended interventie via an explicit override toggle. When the toggle is engaged, the system SHALL present a sanction dropdown and a `justification` text field. Submitting an override SHALL persist `override = true`, `appliedInterventie`, and `overrideJustification` (≥ 20 characters) on the `sanctionRecommendation` record. An override that selects an interventie of *higher* severity than the recommended one SHALL be permitted only for users carrying the manager role.

**Feature tier**: V1

#### Scenario: Override-down with justification succeeds

- **GIVEN** the matrix recommends `last_onder_dwangsom` for the selected triple
- **WHEN** the inspector enables the override toggle, picks `waarschuwing` (lower severity), enters a 25-character justification, and submits
- **THEN** the saved `sanctionRecommendation` SHALL have `override = true`, `appliedInterventie = "waarschuwing"`, and the supplied `overrideJustification`
- **AND** the resulting `handhavingsactie` SHALL reference the recommendation's ID

#### Scenario: Override-up requires manager role

- **GIVEN** the matrix recommends `waarschuwing` and an inspector without manager role attempts to override to `bestuursdwang`
- **WHEN** the submission is processed
- **THEN** the service SHALL return HTTP 403 with the message `Verzwaring vereist managerrol`
- **AND** the UI SHALL NOT have offered `bestuursdwang` as a selectable option for non-managers

### Requirement: REQ-LHS-7 — Audit-trail integration with vth-module workflow

The system SHALL append an `lhs_recommendation` event to the parent case timeline (as exposed by the `vth-module` enforcement workflow) for every `recommend` and `override` call. The event payload SHALL include `{ recommendationId, ernst, gedrag, actorType, recommended, applied, override }`. The OpenRegister per-save audit log SHALL additionally capture the `sanctionRecommendation` mutation, providing a second, lower-level audit channel.

**Feature tier**: V1

#### Scenario: Recommendation appears on case timeline

- **GIVEN** a case in the enforcement workflow with no prior LHS recommendation
- **WHEN** an inspector calls `recommend(caseId, "gering", "goedwillend", "burger")` and the service returns `recommendedInterventie = "waarschuwing"`
- **THEN** an event of type `lhs_recommendation` SHALL appear on the case timeline
- **AND** the event payload SHALL contain `recommendationId`, `ernst = "gering"`, `gedrag = "goedwillend"`, `actorType = "burger"`, `recommended = "waarschuwing"`, `applied = "waarschuwing"`, `override = false`

#### Scenario: Override produces a second timeline event

- **GIVEN** a case where a recommendation event has already been recorded
- **WHEN** the inspector later submits an override on the same recommendation
- **THEN** a second `lhs_recommendation` event SHALL be appended with the same `recommendationId` but `applied` reflecting the new interventie and `override = true`
- **AND** the chronological order of the two events SHALL be preserved

### Requirement: REQ-LHS-8 — Matrix versioning and snapshot stability

The system SHALL treat the `lhsMatrix` as immutable per version. Edits made by an administrator SHALL create a new `lhsMatrix` record with `version = previousVersion + 1`; the new record SHALL receive `active = true` while the prior matrix SHALL have its `active` flag flipped to `false`. Each `sanctionRecommendation` SHALL store the `matrixVersion` it was looked up against; subsequent matrix edits SHALL NOT mutate the `recommendedInterventie` of prior recommendations.

**Feature tier**: V1

#### Scenario: Edit creates a new version

- **GIVEN** matrix M1 with `version = 1`, `active = true`
- **WHEN** an administrator saves a cell change via the admin grid editor
- **THEN** a new matrix M2 SHALL be persisted with `version = 2`, `active = true`
- **AND** M1 SHALL be updated to `active = false`
- **AND** at most one `lhsMatrix` SHALL have `active = true` per tenant

#### Scenario: Historical recommendation references frozen version

- **GIVEN** a `sanctionRecommendation` R1 recorded against matrix M1 (`version = 1`, `recommendedInterventie = "herstelactie"`)
- **WHEN** an administrator publishes M2 in which the same cell now resolves to `"last_onder_dwangsom"`
- **THEN** R1's `recommendedInterventie` SHALL remain `"herstelactie"`
- **AND** R1's `matrixVersion` SHALL remain `1`
- **AND** new recommendations made after the publish SHALL use M2
