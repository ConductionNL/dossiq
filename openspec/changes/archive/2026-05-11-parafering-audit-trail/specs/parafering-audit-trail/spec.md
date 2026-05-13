## ADDED Requirements

### Requirement: paraferingAuditEntry Schema Registration (REQ-PAT-1)

The system SHALL register a `paraferingAuditEntry` schema in the Procest OpenRegister configuration with the properties `voorstel`, `step`, `transitionType`, `actor`, `actorRole`, `timestamp`, `reason`, `contentSnapshot`, `ipAddress`, and `auditEntryHash`. The required-property list SHALL be exactly `[voorstel, transitionType, actor, actorRole, timestamp, contentSnapshot, ipAddress, auditEntryHash]`. The `transitionType` enum SHALL be exactly `[started, paraferd, advised, terugsturen, route-changed, completed]`. The `actorRole` enum SHALL be exactly `[steller, adviseur, parafeerder, accorderend, beheerder, secretariaat]`. The Schema.org type SHALL be `schema:Action`. A `paraferingAuditEntry` is the canonical legal-accountability record of a single transition on a voorstel and SHALL be the only entity carrying transition-level audit semantics for the parafering lifecycle.

**Feature tier**: V1
**Schema.org type**: `schema:Action`
**ZGW mapping**: No direct ZGW counterpart — the audit trail is a procest-internal accountability artefact distinct from `AuditTrail` resources on `Zaak`/`Besluit`.

#### Scenario: Schema is registered after app install

- **WHEN** the Procest app is installed or updated via the repair step
- **THEN** the `paraferingAuditEntry` schema SHALL exist in the Procest register
- **AND** the schema SHALL enforce required properties `voorstel`, `transitionType`, `actor`, `actorRole`, `timestamp`, `contentSnapshot`, `ipAddress`, `auditEntryHash`
- **AND** the `transitionType` enum SHALL accept exactly the 6 listed values
- **AND** previously imported seed entries SHALL be importable idempotently — no duplicates on re-import (verified by slug match)

#### Scenario: Audit entry rejects unknown transitionType

- **GIVEN** an insert request for a `paraferingAuditEntry` with `transitionType = "submitted"` (not in the enum)
- **WHEN** the OpenRegister write pipeline validates the object
- **THEN** OpenRegister SHALL reject the object with a schema validation error
- **AND** no `paraferingAuditEntry` SHALL be persisted

### Requirement: Event-Sourced Audit Writes Only (REQ-PAT-2)

The system SHALL write `paraferingAuditEntry` records EXCLUSIVELY from a single `ParaferingAuditListener` that subscribes to a `ParafeerTransitionEvent` domain event. Application services SHALL NOT call `ObjectService::saveObject` for the `paraferingAuditEntry` schema directly. Every parafeerroute transition (`startRoute`, `completeStep`, `skipStep`, `addAdHocStep`, `recordAction`) SHALL dispatch the event AFTER the operational save succeeds and BEFORE the originating service method returns.

**Feature tier**: V1

#### Scenario: startRoute dispatches a started transition event

- **GIVEN** a voorstel with `status = concept` and a linked parafeerroute
- **WHEN** the steller calls `POST /api/parafeer-route/voorstel/{id}/start` and `ParafeerRouteService::startRoute` completes successfully
- **THEN** `ParafeerRouteService` SHALL dispatch one `ParafeerTransitionEvent` with `transitionType = "started"`, `actor` = the steller UID, `actorRole = "steller"`
- **AND** the `ParaferingAuditListener` SHALL persist exactly one `paraferingAuditEntry` for this transition within the same request lifecycle

#### Scenario: recordAction dispatches the matching transition event

- **GIVEN** the current step actor records `action = "parafered"` for step 2
- **WHEN** `ParafeerActieService::recordAction` saves the `parafeeractie` and returns success
- **THEN** the service SHALL dispatch `ParafeerTransitionEvent` with `transitionType = "paraferd"`, `actor` = the session UID, `actorRole = "parafeerder"`, `step = 2`
- **AND** a corresponding `paraferingAuditEntry` SHALL exist for that voorstel

#### Scenario: Audit writes never come from outside the listener

- **GIVEN** any code path other than the `ParaferingAuditListener`
- **WHEN** that code path calls `ObjectService::saveObject` with schema `paraferingAuditEntry`
- **THEN** the validator (REQ-PAT-3) SHALL reject the write because the `auditEntryHash` was not derived through the canonical listener pipeline
- **AND** the write SHALL return HTTP 403 with the static body `{"message": "Audit entries are append-only"}`

### Requirement: Append-Only Enforcement (REQ-PAT-3)

The system SHALL enforce append-only semantics on `paraferingAuditEntry` objects via a validator hooked into OpenRegister's pre-save pipeline. Any UPDATE or DELETE operation on an existing `paraferingAuditEntry` SHALL be rejected with HTTP 403 and the static message `Audit entries are append-only`. The static message SHALL NOT include exception details, stack traces, or the schema name of the rejected operation.

**Feature tier**: V1

#### Scenario: UPDATE of audit entry rejected

- **GIVEN** an existing `paraferingAuditEntry` with id E1
- **WHEN** any authenticated user (including administrators) calls the OpenRegister generic update endpoint with target id E1 and a changed `reason` field
- **THEN** the validator SHALL reject the request
- **AND** the response SHALL be HTTP 403 with body `{"message": "Audit entries are append-only"}`
- **AND** the underlying record SHALL remain byte-identical (verified by `auditEntryHash` match before and after)

#### Scenario: DELETE of audit entry rejected

- **GIVEN** an existing `paraferingAuditEntry` with id E1
- **WHEN** any authenticated user calls the OpenRegister generic delete endpoint with target id E1
- **THEN** the validator SHALL reject the request with HTTP 403 and body `{"message": "Audit entries are append-only"}`
- **AND** the record SHALL remain present in the register

#### Scenario: INSERT requires canonical hash

- **GIVEN** a direct INSERT attempt on `paraferingAuditEntry` (bypassing the listener) carrying a missing or non-64-hex `auditEntryHash`
- **WHEN** the validator inspects the payload
- **THEN** the validator SHALL reject with HTTP 400 and static message `Invalid audit hash`
- **AND** no `paraferingAuditEntry` SHALL be persisted

### Requirement: Reuse of OpenRegister audit-trail-immutable (REQ-PAT-4)

The system SHALL build on top of OpenRegister's `audit-trail-immutable` capability for the underlying mutation log — every save, attempted update, and attempted delete on `paraferingAuditEntry` SHALL be recorded by that OpenRegister capability. The procest spec SHALL NOT introduce a parallel hash-chain, Merkle tree, or custom append store. Tamper detection SHALL rely on the combination of (a) the per-entry `auditEntryHash` and (b) the OpenRegister raw-mutation audit log.

**Feature tier**: V1

#### Scenario: Storage-level tampering is detectable

- **GIVEN** an out-of-band actor mutates a `paraferingAuditEntry` row directly at the database layer (bypassing the API and the validator)
- **WHEN** an auditor recomputes `sha256` of the canonical JSON of the entry (excluding `auditEntryHash` itself) and compares it to the stored `auditEntryHash`
- **THEN** the values SHALL differ — proving the row was tampered with
- **AND** the OpenRegister `audit-trail-immutable` capability SHALL ALSO have recorded the raw row mutation, giving a second independent detection signal

#### Scenario: No custom audit store introduced

- **GIVEN** the procest codebase
- **WHEN** a reviewer searches `lib/` for any custom audit store, hash chain, or Merkle implementation
- **THEN** no such code SHALL exist — all persistence flows through `ObjectService` and storage immutability flows through OpenRegister

### Requirement: Content Snapshot at Transition Moment (REQ-PAT-5)

The system SHALL capture an immutable JSON copy of the voorstel content fields at the moment of each transition. The snapshot SHALL contain exactly the fields `onderwerp`, `document`, `bijlagen`, `routeSnapshot`, `currentStep`, and `status` from the voorstel as of the transition. The snapshot SHALL be stored verbatim in `paraferingAuditEntry.contentSnapshot` and SHALL NOT be a reference, lazy proxy, or pointer — subsequent changes to the voorstel MUST NOT alter the captured snapshot.

**Feature tier**: V1

#### Scenario: Snapshot frozen at transition time

- **GIVEN** a voorstel with `onderwerp = "Uitbreiding parkeerterrein Raadhuis"` at the moment step 2 is parafered
- **WHEN** the `paraferd` transition fires and the listener captures the snapshot
- **THEN** the audit entry SHALL store `contentSnapshot.onderwerp = "Uitbreiding parkeerterrein Raadhuis"`
- **AND** if the steller later resubmits with `onderwerp = "Uitbreiding parkeerterrein Raadhuis — herzien"` after a teruggestuurd cycle, the original audit entry SHALL still record the original onderwerp

#### Scenario: Snapshot fields are exactly the canonical content set

- **GIVEN** any transition is being recorded
- **WHEN** the listener builds the snapshot
- **THEN** the snapshot SHALL contain exactly six keys: `onderwerp`, `document`, `bijlagen`, `routeSnapshot`, `currentStep`, `status`
- **AND** the snapshot SHALL NOT contain other voorstel fields (no `parafeerroute` reference, no `behandeling`, no `case` cross-reference) — those are denormalised lookups outside the legal scope

### Requirement: Archive Export Endpoint (REQ-PAT-6)

The system SHALL expose `GET /api/parafering-audit-trail/export?voorstel={uuid}` returning a JSON export containing an MDTO-aligned `metadata` block and a chronologically sorted `entries` array. The endpoint SHALL be limited to members of one of the groups `auditors`, `secretariaat`, or `beheerders`. Non-members SHALL receive HTTP 403 with the static message `Audit export requires auditor role`.

**Feature tier**: V1

#### Scenario: Export shape conforms to MDTO 1.0

- **GIVEN** a voorstel U1 with 6 `paraferingAuditEntry` records (one per transitionType)
- **WHEN** an auditor in the `auditors` group calls `GET /api/parafering-audit-trail/export?voorstel=U1`
- **THEN** the response SHALL be HTTP 200 with body `{"metadata": {...}, "entries": [...]}`
- **AND** `metadata.schema` SHALL be the string `MDTO 1.0`
- **AND** `metadata.entryCount` SHALL equal `6`
- **AND** `entries` SHALL be sorted ascending by `timestamp`
- **AND** `metadata.retentionUntil` SHALL equal the timestamp of the `completed` entry plus 20 years (ISO 8601 date)

#### Scenario: Non-auditor blocked

- **GIVEN** authenticated user U2 who is NOT a member of `auditors`, `secretariaat`, or `beheerders`
- **WHEN** U2 calls `GET /api/parafering-audit-trail/export?voorstel=U1`
- **THEN** the response SHALL be HTTP 403 with body `{"message": "Audit export requires auditor role"}`
- **AND** no audit content SHALL be disclosed in the response body

#### Scenario: Missing voorstel parameter

- **GIVEN** a request `GET /api/parafering-audit-trail/export` without a `voorstel` query parameter
- **WHEN** the controller validates the request
- **THEN** the response SHALL be HTTP 400 with body `{"message": "Missing required parameter: voorstel"}`

### Requirement: Manifest Index Page for Auditor Browsing (REQ-PAT-7)

The system SHALL declare a manifest page of `type: 'index'` for the `paraferingAuditEntry` schema in `procest_register.json` so that auditors and beheerders can browse, filter, and sort audit entries through the OpenRegister manifest UI without bespoke Vue components. The index SHALL support filtering by `transitionType`, `actor`, `voorstel`, and timestamp range.

**Feature tier**: V1

#### Scenario: Index page reachable at /apps/procest/parafering-audit

- **GIVEN** the Procest app is installed and the manifest config has been imported
- **WHEN** an auditor navigates to `/apps/procest/parafering-audit`
- **THEN** the manifest router SHALL render the index page
- **AND** the page SHALL list all `paraferingAuditEntry` records with columns `transitionType`, `actor`, `actorRole`, `timestamp`, `voorstel`
- **AND** filter controls SHALL be available for `transitionType`, `actor`, and timestamp range

#### Scenario: No bespoke Vue view exists

- **GIVEN** the procest frontend source tree
- **WHEN** a reviewer searches `src/views/` for a custom audit-trail listing component
- **THEN** no such bespoke view SHALL exist — the manifest pattern alone provides the index
- **AND** procest-specific labelling SHALL be expressed only via `components.x-pages[].listing.columns[]` overrides in `procest_register.json`

### Requirement: Retention Policy Aligned with Archiefwet (REQ-PAT-8)

The system SHALL apply a retention policy of 20 years from the `completed` transition timestamp for voorstellen that produced a besluit, aligned with Selectielijst Gemeenten 2020 category "Bestuurlijke besluitvorming". For voorstellen that ended without a `completed` transition (withdrawn or permanently teruggestuurd) the retention SHALL be 7 years, aligned with administrative-correspondence retention. The retention window SHALL be surfaced via `metadata.retentionUntil` on the archive export. Deletion before the retention boundary SHALL be impossible because of the append-only validator (REQ-PAT-3); a future automated retention sweeper is out of scope of this change and SHALL be filed as a follow-up issue before any production retention enforcement is enabled.

**Feature tier**: V1

#### Scenario: 20-year retention for decisions

- **GIVEN** a voorstel that completed with a `completed` transition at `2026-05-11T10:00:00Z`
- **WHEN** the archive export is generated
- **THEN** `metadata.retentionUntil` SHALL equal `2046-05-11`
- **AND** `metadata.selectielijstCategory` SHALL equal `Bestuurlijke besluitvorming — bewaartermijn 20 jaar`

#### Scenario: 7-year retention for non-decisions

- **GIVEN** a voorstel that has audit entries but no `completed` transition (e.g. withdrawn at the teruggestuurd stage)
- **WHEN** the archive export is generated
- **THEN** `metadata.retentionUntil` SHALL equal the timestamp of the LATEST audit entry plus 7 years (ISO 8601 date)
- **AND** `metadata.selectielijstCategory` SHALL equal `Administratieve correspondentie — bewaartermijn 7 jaar`

#### Scenario: Deletion blocked irrespective of retention window

- **GIVEN** an audit entry older than 21 years (theoretically past the 20-year window)
- **WHEN** any caller attempts a DELETE on that entry via the API
- **THEN** the append-only validator SHALL still reject the request with HTTP 403 and body `{"message": "Audit entries are append-only"}`
- **AND** the record SHALL remain present until an out-of-scope automated retention sweep removes it
