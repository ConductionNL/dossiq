## ADDED Requirements

### Requirement: REQ-IC-1 — Checklist Template Entity

The system SHALL store inspection checklist templates as `checklistTemplate` objects in OpenRegister. Each template SHALL declare `name`, `caseType` (optional ref), `version` (integer ≥ 1, monotonic), `status` (enum: `draft`, `active`, `retired`), `sections[]` (ordered with name + description), and `items[]`. Each `checklistItem` SHALL declare `order`, `label`, `helpText`, `responseType` (enum: `ja_nee_nvt`, `tekst`, `getal`, `meerkeuze`, `foto`, `meting`), `required`, `fotoRequired` (enum: `nooit`, `bij_nee`, `altijd`), `numericRange` (only when `responseType ∈ {getal, meting}`), `choices[]` (only when `responseType = meerkeuze`), and `failureAction` (object with `type ∈ {herinspectie, handhavingstaak, documentVerzoek, geen}`, `template`, `deadlineDays`). Required properties on template: `name`, `version`, `status`, `sections`.

**Feature tier**: V2
**Schema.org**: `schema:HowTo` (template), `schema:HowToStep` (item)
**ZGW mapping**: Custom extension — no ZGW equivalent

#### Scenario: Schema registered on app install

- **GIVEN** the Procest app is installed or upgraded
- **WHEN** the `InitializeSettings` repair step runs
- **THEN** the `checklistTemplate` schema SHALL exist in the Procest register
- **AND** the `responseType` enum SHALL be exactly `[ja_nee_nvt, tekst, getal, meerkeuze, foto, meting]`
- **AND** the `failureAction.type` enum SHALL be exactly `[herinspectie, handhavingstaak, documentVerzoek, geen]`

#### Scenario: Template rejects unknown response type

- **GIVEN** an admin posting a new template with item `responseType = "stempel"` (not in enum)
- **WHEN** the create request is validated
- **THEN** OpenRegister SHALL reject the object with a schema validation error
- **AND** no template SHALL be persisted

#### Scenario: numericRange required for getal items

- **GIVEN** an admin creating an item with `responseType = "getal"` and no `numericRange`
- **WHEN** the template is saved
- **THEN** the system SHALL reject the save with error `NUMERIC_RANGE_REQUIRED`

---

### Requirement: REQ-IC-2 — Checklist Run Entity

The system SHALL store per-inspection runtime instances as `checklistRun` objects in OpenRegister. Each run SHALL declare `case` (ref), `template` (ref), `templateVersion`, `templateSnapshot` (hidden JSON), `inspector` (NC UID), `startedAt`, `completedAt`, `status` (enum: `concept`, `in_uitvoering`, `ingediend`, `gearchiveerd`), `responses[]`, `location {lat, lng, accuracy, source}`, `overallResult` (enum: `conform`, `niet_conform`, `deels_conform`), `syncState` (enum: `local`, `queued`, `synced`). Required: `case`, `template`, `templateVersion`, `inspector`, `status`. `inspector` SHALL be server-derived from `IUserSession` — never accepted from the request body.

**Feature tier**: V2
**Schema.org**: `schema:Action` (run), `schema:ReviewAction` (each response)

#### Scenario: Start run freezes template snapshot

- **GIVEN** an inspector starting a run against `checklistTemplate` v3
- **WHEN** `POST /api/inspecties/runs` is called with `templateId` and `caseId`
- **THEN** the system SHALL create a `checklistRun` with `status = concept`, `templateVersion = 3`, `templateSnapshot` populated with the JSON-encoded `sections[]` + `items[]` of v3
- **AND** `inspector` SHALL be set from `IUserSession`, ignoring any body-supplied UID

#### Scenario: Body-supplied inspector UID is ignored

- **GIVEN** an inspector authenticated as `pieter`
- **WHEN** they POST a run with body `{inspector: "lisa", ...}`
- **THEN** the persisted run SHALL have `inspector = "pieter"`

---

### Requirement: REQ-IC-3 — Per-Item Validation Rules

The system SHALL validate each response against the item's `responseType` and configured constraints, both client-side and server-side. `ja_nee_nvt` SHALL accept exactly `ja`, `nee`, `nvt`. `getal` and `meting` SHALL enforce `numericRange.min ≤ value ≤ numericRange.max`. `meerkeuze` SHALL accept only values in `choices[]`. `foto` items SHALL require ≥ 1 photo. `tekst` SHALL enforce max 2000 characters. When `fotoRequired = bij_nee` and the response is `nee`, the response SHALL be rejected unless ≥ 1 photo is attached. When `fotoRequired = altijd`, the response SHALL be rejected unless ≥ 1 photo is attached regardless of value.

**Feature tier**: V2

#### Scenario: Numeric out-of-range rejected

- **GIVEN** an item with `responseType = getal`, `numericRange = {min: 0, max: 120, unit: "dB"}`
- **WHEN** an inspector submits a response with `numericValue = 150`
- **THEN** the system SHALL reject the response with error `OUT_OF_RANGE`
- **AND** the response SHALL NOT be persisted

#### Scenario: Photo required on "nee" enforced

- **GIVEN** an item with `responseType = ja_nee_nvt` and `fotoRequired = bij_nee`
- **WHEN** an inspector submits `value = nee` with empty `photos[]`
- **THEN** the system SHALL reject the response with error `PHOTO_REQUIRED`

#### Scenario: Choice value outside enum rejected

- **GIVEN** an item with `responseType = meerkeuze`, `choices = ["A", "B", "C"]`
- **WHEN** an inspector submits `choice = "D"`
- **THEN** the system SHALL reject the response with error `INVALID_CHOICE`

---

### Requirement: REQ-IC-4 — Evidence Linking

The system SHALL link photos, audio recordings, and free-text comments as evidence to specific items within a run. Files SHALL be stored in Nextcloud Files under `/Procest/Zaken/{caseId}/Inspecties/{runId}/items/{itemId}/`. Responses SHALL hold Nextcloud file IDs, never paths. Files SHALL inherit the case's ACL via folder-level sharing. Once a run reaches `status = ingediend`, evidence SHALL become append-only: no file deletes, moves, or response edits.

**Feature tier**: V2

#### Scenario: Photo upload routed to case folder

- **GIVEN** an active run on case `2026-089`, run id `r123`, item `i7`
- **WHEN** the inspector uploads a photo `IMG_001.jpg` against item `i7`
- **THEN** the file SHALL be created at `/Procest/Zaken/2026-089/Inspecties/r123/items/i7/IMG_001.jpg`
- **AND** the response for item `i7` SHALL store the Nextcloud file ID (not the path)

#### Scenario: Post-submit evidence write blocked

- **GIVEN** a run with `status = ingediend`
- **WHEN** an inspector attempts to upload an additional photo
- **THEN** the system SHALL return HTTP 403 with error `RUN_IMMUTABLE`
- **AND** no file SHALL be created

#### Scenario: File rename does not break link

- **GIVEN** a response storing Nextcloud file ID `42`
- **WHEN** the underlying file is renamed in Nextcloud Files
- **THEN** the response SHALL still resolve to the renamed file via its file ID

---

### Requirement: REQ-IC-5 — Offline-Capable Completion

The system SHALL support running a checklist offline. Mobile clients SHALL persist responses and evidence to an IndexedDB queue keyed by `runId`. `syncState` per response and per evidence file SHALL transition through `local → queued → synced`. On reconnect, the sync worker SHALL drain responses (idempotent JSON keyed by `{runId, itemId}`) before evidence files (chunked upload with exponential backoff). Conflicts on the same `{runId, itemId}` SHALL surface a chooser to the inspector; local writes SHALL NOT silently overwrite server state.

**Feature tier**: V3

#### Scenario: Offline run completes locally

- **GIVEN** the inspector has loaded a template while online
- **WHEN** the device goes offline and the inspector answers 8 items + attaches 3 photos
- **THEN** all 8 responses and 3 photos SHALL persist to IndexedDB
- **AND** each SHALL carry `syncState = local`
- **AND** the run SHALL render correctly without network

#### Scenario: Sync drains responses before photos

- **GIVEN** 16 queued responses and 6 queued photos
- **WHEN** the device reconnects and the sync worker starts
- **THEN** all 16 responses SHALL reach `syncState = synced` before any photo upload begins
- **AND** photos SHALL upload with exponential backoff on transient errors

#### Scenario: Conflict surfaces chooser, no silent overwrite

- **GIVEN** a response for `{runId: r1, itemId: i3}` queued locally as `value = ja`
- **AND** the server already has `value = nee` for the same `{runId, itemId}` with later `updatedAt`
- **WHEN** the sync worker pushes the local response
- **THEN** the system SHALL detect the conflict and surface both versions to the inspector
- **AND** the local value SHALL NOT silently overwrite the server value

---

### Requirement: REQ-IC-6 — Pass/Fail Aggregation

The system SHALL derive `overallResult` from response values on submit, never accepting a user-set value. An item SHALL pass when its value is `ja` (for `ja_nee_nvt`), within `numericRange` (for `getal`/`meting`), in `choices` (for `meerkeuze`), or has ≥ 1 photo (for `foto`). An item SHALL fail when its value is `nee` or out-of-range. An item SHALL be skipped when its value is `nvt`. Run aggregate SHALL be: `conform` (0 fails), `niet_conform` (≥ 1 fail and 0 skipped), `deels_conform` (≥ 1 fail and ≥ 1 skipped). Per-section results SHALL be computed identically and exposed alongside the overall result.

**Feature tier**: V2

#### Scenario: All items pass → conform

- **GIVEN** a run with 8 responses, all `value = ja` or `value = nvt`, no fails
- **WHEN** the inspector submits the run
- **THEN** `overallResult` SHALL be `conform`

#### Scenario: One fail, no skips → niet_conform

- **GIVEN** a run with 8 responses where 1 is `nee` and 7 are `ja`
- **WHEN** the run is submitted
- **THEN** `overallResult` SHALL be `niet_conform`

#### Scenario: Failures and skips mixed → deels_conform

- **GIVEN** a run with 8 responses where 1 is `nee`, 1 is `nvt`, and 6 are `ja`
- **WHEN** the run is submitted
- **THEN** `overallResult` SHALL be `deels_conform`
- **AND** per-section results SHALL be returned alongside

#### Scenario: User-set overallResult ignored

- **GIVEN** an inspector submitting a run with body `{overallResult: "conform"}` despite 3 fails
- **WHEN** the submit request is processed
- **THEN** the body-supplied value SHALL be ignored
- **AND** the persisted `overallResult` SHALL be derived from responses (`niet_conform`)

---

### Requirement: REQ-IC-7 — Conditional Follow-Up Actions

The system SHALL dispatch follow-up actions on run submit based on per-item `failureAction` configuration. For each failed item with `failureAction.type ≠ geen`, the system SHALL create a task on the parent case of the configured type. `herinspectie` SHALL produce a re-inspection task with deadline `now + deadlineDays`. `handhavingstaak` SHALL hand off to the `enforcement-lhs` capability. `documentVerzoek` SHALL create a document-request task. Each created task SHALL reference both the run id and the specific item id for drill-down.

**Feature tier**: V2

#### Scenario: Failed wapening item creates herinspectie task

- **GIVEN** an item with `failureAction = {type: herinspectie, deadlineDays: 14}`
- **WHEN** the run is submitted with that item failed
- **THEN** the system SHALL create a task of type `herinspectie` on the parent case
- **AND** the task deadline SHALL be `submittedAt + 14 days`
- **AND** the task SHALL reference `runId` and `itemId`

#### Scenario: Multiple failures dispatch independent follow-ups

- **GIVEN** a run with 3 failed items, each with a different `failureAction.type` (herinspectie, handhavingstaak, documentVerzoek)
- **WHEN** the run is submitted
- **THEN** the system SHALL create 3 distinct tasks on the case, one per failure
- **AND** each task SHALL be of the correct type

#### Scenario: failureAction.type = geen creates no task

- **GIVEN** a failed item with `failureAction.type = geen`
- **WHEN** the run is submitted
- **THEN** no follow-up task SHALL be created for that item

---

### Requirement: REQ-IC-8 — Template Versioning and Run Append-Only

The system SHALL bump `version` on every published edit to an `active` template, retaining all prior versions as immutable historical records. Once a run is started against a template version, `templateSnapshot` SHALL be frozen and the run SHALL retain its reference to that snapshot regardless of subsequent template edits. Runs in `status = ingediend` SHALL become append-only: `recordResponse`, evidence uploads, and direct schema writes to response objects SHALL all be rejected. Corrections to a submitted run SHALL require starting a new run; the prior run SHALL remain immutable evidence.

**Feature tier**: V2

#### Scenario: Edit published template bumps version

- **GIVEN** a `checklistTemplate` v3 with `status = active`
- **WHEN** an admin edits the template and republishes
- **THEN** the system SHALL create v4 with `status = active`
- **AND** v3 SHALL transition to `status = retired` (immutable)

#### Scenario: In-flight run retains old snapshot after template edit

- **GIVEN** a run started against template v3 with `templateSnapshot` frozen
- **WHEN** the template is republished as v4 with different items
- **THEN** the run SHALL still render its frozen v3 snapshot
- **AND** the run SHALL retain `templateVersion = 3`

#### Scenario: Submitted run rejects further writes

- **GIVEN** a run with `status = ingediend`
- **WHEN** `ChecklistService::recordResponse` is called with any payload
- **THEN** the call SHALL throw `RUN_IMMUTABLE`
- **AND** no response SHALL be added or modified
