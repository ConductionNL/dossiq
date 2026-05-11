## ADDED Requirements

### Requirement: Voorstel Entity and Schema Registration

The system SHALL register a `voorstel` schema in the Procest OpenRegister configuration. The schema SHALL declare the properties `case`, `type`, `onderwerp`, `steller`, `afdeling`, `portefeuillehouder`, `status`, `parafeerroute`, `routeSnapshot`, `currentStep`, `returnedFromStep`, `document`, `bijlagen`, and `behandeling`. The required-property list SHALL be exactly `[case, type, onderwerp, steller, status]`. The `type` enum SHALL be exactly `[dt_advies, collegeadvies, raadsvoorstel]`. The Schema.org type SHALL be `schema:CreativeWork`. A voorstel is the central unit of work for B&W parafering and SHALL be the only entity that owns a parafering lifecycle.

**Feature tier**: V1
**Schema.org type**: `schema:CreativeWork`
**ZGW mapping**: bridges to `Besluit` only after the bestuurlijk phase completes; voorstellen themselves have no direct ZGW equivalent.

#### Scenario: Schema is registered after app install

- **WHEN** the Procest app is installed or updated via the repair step
- **THEN** the `voorstel` schema SHALL exist in the Procest register
- **AND** the schema SHALL enforce the required properties `case`, `type`, `onderwerp`, `steller`, `status`
- **AND** the `type` enum SHALL be exactly `[dt_advies, collegeadvies, raadsvoorstel]`

#### Scenario: Voorstel rejects unknown type

- **GIVEN** the steller posts a new voorstel with `type = "bestemmingsplan"` (not in the enum)
- **WHEN** the create request is validated
- **THEN** OpenRegister SHALL reject the object with a schema validation error
- **AND** no voorstel SHALL be persisted

### Requirement: Voorstel Status Lifecycle

The system SHALL maintain a voorstel through an eight-state lifecycle: `concept`, `in_parafering`, `ter_accordering`, `geaccordeerd`, `aangeboden`, `besloten`, `gearchiveerd`, and `teruggestuurd`. The default status on creation SHALL be `concept`. Transitions SHALL be driven exclusively by the parafering services (`ParafeerRouteService`, `ParafeerActieService`) and SHALL never be applied via raw schema writes from the frontend.

**Feature tier**: V1

#### Scenario: Submit voorstel for parafering

- **GIVEN** a voorstel with `status = concept`, a non-empty `document`, and a `parafeerroute` reference
- **WHEN** the steller calls `POST /api/parafeer-route/voorstel/{voorstelId}/start`
- **THEN** `status` SHALL transition to `in_parafering`
- **AND** `currentStep` SHALL be set to `1`
- **AND** `routeSnapshot` SHALL contain the parafeerroute's `steps[]` array as a JSON-encoded string

#### Scenario: Voorstel returned to steller

- **GIVEN** a voorstel with `status = in_parafering` at step 2
- **WHEN** the current step actor records `action = returned` with a non-empty reason
- **THEN** `status` SHALL transition to `teruggestuurd`
- **AND** `returnedFromStep` SHALL be set to 2
- **AND** `currentStep` SHALL remain at 2 (no routing advance)
- **AND** the steller SHALL receive a Nextcloud notification carrying the return reason

#### Scenario: Voorstel reaches final endorsement

- **GIVEN** a voorstel with `status = in_parafering` whose `currentStep` is the final step of `routeSnapshot` and the step type is `accordering`
- **WHEN** the final actor records `action = accorded`
- **THEN** `status` SHALL transition to `geaccordeerd`
- **AND** no further parafering steps SHALL be activated

### Requirement: Create Voorstel from Case

The system SHALL support creating a voorstel from within a case context. The voorstel SHALL be linked to the parent case via `case` and SHALL pre-fill `onderwerp`, `afdeling`, and `portefeuillehouder` from the case context when available. `steller` SHALL be set to the authenticated user's UID derived from `IUserSession` — never from request body input.

**Feature tier**: V1

#### Scenario: Create collegeadvies voorstel from case detail

- **GIVEN** a case "Bestemmingsplan Centrum" with an `afdeling` and `portefeuillehouder` configured on its case type
- **WHEN** the steller posts to `POST /api/parafering/voorstellen` with `case = <caseId>` and `type = collegeadvies`
- **THEN** the system SHALL create a voorstel with `status = concept`
- **AND** `onderwerp` SHALL default to the case title when no explicit onderwerp is supplied
- **AND** `steller` SHALL equal the authenticated user's UID
- **AND** `afdeling` and `portefeuillehouder` SHALL be inherited from the case type configuration

#### Scenario: Steller cannot impersonate another user

- **GIVEN** user K. Vermeulen is authenticated
- **WHEN** the request body sets `steller = "p.janssen"` (a different UID)
- **THEN** the saved voorstel SHALL still record `steller = "k.vermeulen"` (the session UID)
- **AND** the request SHALL NOT be rejected for that field — the value SHALL simply be ignored

### Requirement: Voorstel ↔ Parafeerroute Binding with Snapshot

The system SHALL allow exactly one `parafeerroute` reference per voorstel. At submission time the route's ordered `steps[]` SHALL be copied into `voorstel.routeSnapshot` as a JSON-encoded array. The snapshot SHALL be the single source of truth for subsequent step advancement, so that later edits to the source parafeerroute do NOT retroactively change in-flight voorstellen.

**Feature tier**: V1

#### Scenario: Snapshot is frozen at submission

- **GIVEN** a voorstel of `type = dt_advies` linked to parafeerroute R1 which has three steps
- **WHEN** the steller submits the voorstel for parafering
- **THEN** `routeSnapshot` SHALL be a JSON-encoded array of three step objects matching R1 at that moment
- **AND** if the administrator subsequently adds a fourth step to R1, the voorstel's `routeSnapshot` SHALL remain three entries

#### Scenario: Cannot delete an in-use parafeerroute

- **GIVEN** at least one voorstel with `status = in_parafering` referencing parafeerroute R1
- **WHEN** an administrator calls `DELETE` on R1
- **THEN** the request SHALL be rejected with HTTP 409 / 422 carrying the message `Route is in gebruik door actieve voorstellen`
- **AND** R1 SHALL remain persisted

### Requirement: Voorstel Detail View

The system SHALL provide a dedicated detail view for a voorstel that renders header metadata, the linked document, parafering progress, and the action history timeline. The detail view SHALL surface action controls only when the current authenticated user is the actor of the current step (or a valid delegate).

**Feature tier**: V1

#### Scenario: View renders voorstel metadata and document

- **WHEN** an authorized case participant opens the voorstel detail view
- **THEN** the view SHALL display `onderwerp`, `type`, `status`, `steller`, `afdeling`, and `portefeuillehouder`
- **AND** the `document` SHALL be viewable inline (Nextcloud preview or download link)
- **AND** all `bijlagen` SHALL be listed and downloadable
- **AND** a back-link to the parent case SHALL be present

#### Scenario: Progress indicator reflects routeSnapshot

- **GIVEN** a voorstel with a five-step `routeSnapshot` where steps 1–3 are completed, step 4 is active, step 5 is pending
- **WHEN** the detail view renders the progress indicator
- **THEN** steps 1–3 SHALL render as completed with actor name and date
- **AND** step 4 SHALL render as active with actor name
- **AND** step 5 SHALL render as pending with actor name

#### Scenario: Action controls hidden for non-actors

- **GIVEN** a voorstel with `status = in_parafering` whose current-step actor is M. Bakker
- **WHEN** user P. Janssen (not the actor and not a delegate) opens the detail view
- **THEN** the "Actie nemen" and "Terugsturen" controls SHALL NOT be rendered
- **AND** the action history timeline SHALL still be visible read-only

### Requirement: Multiple Voorstellen per Case

The system SHALL support multiple voorstellen on a single case. Each voorstel SHALL maintain an independent `status`, `parafeerroute`, `routeSnapshot`, and `currentStep`. Status transitions on one voorstel SHALL NOT affect any sibling voorstel.

**Feature tier**: V1

#### Scenario: Independent status per voorstel

- **GIVEN** a case with two voorstellen: V1 (`type = dt_advies`, `status = besloten`) and V2 (`type = collegeadvies`, `status = concept`)
- **WHEN** the steller submits V2 for parafering
- **THEN** V2's `status` SHALL transition to `in_parafering`
- **AND** V1's `status` SHALL remain `besloten`
- **AND** both voorstellen SHALL appear in the case detail's voorstellen list

### Requirement: Voorstel Audit Trail and Immutability After Submission

The system SHALL record every voorstel mutation in the OpenRegister automatic audit trail. After a voorstel transitions out of `concept` (or `teruggestuurd` after edits), the content fields `onderwerp`, `document`, and `bijlagen` SHALL be locked against modification — only lifecycle services may continue to update `status`, `currentStep`, `routeSnapshot`, `returnedFromStep`, and append-only audit entries. Route overrides (skip / ad-hoc step) SHALL append entries to the voorstel `auditTrail` field with actor, timestamp, and reason.

**Feature tier**: V1

#### Scenario: Content lock after submission

- **GIVEN** a voorstel with `status = in_parafering`
- **WHEN** any caller other than `ParafeerRouteService` or `ParafeerActieService` attempts to update `onderwerp`, `document`, or `bijlagen`
- **THEN** the update SHALL be rejected with HTTP 409 carrying a Dutch message such as `Voorstel is in parafering en kan niet worden bewerkt`
- **AND** the OpenRegister audit log SHALL still capture the attempt

#### Scenario: Route override is auditable

- **GIVEN** an authorized manager skips step 2 of an in-flight voorstel via `POST /api/parafeer-route/voorstel/{id}/skip-step` with reason "Spoedprocedure"
- **WHEN** the skip is applied
- **THEN** an entry SHALL be appended to `voorstel.auditTrail` containing the actor UID, timestamp, step number, and reason
- **AND** the OpenRegister per-save audit log SHALL also record the change

### Requirement: Voorstel Security and Authorization

The system SHALL derive the acting user identity exclusively from `IUserSession` for every voorstel-mutating endpoint. Read access SHALL be limited to participants of the parent case (including externally shared participants per `case-sharing-collaboration`). Write access SHALL be limited to: the steller (while `status ∈ {concept, teruggestuurd}`), the current step actor (or a valid delegate), or an authorized manager performing a route override.

**Feature tier**: V1

#### Scenario: Unrelated user cannot read voorstel

- **GIVEN** a voorstel on case Z1 and an authenticated user U2 who is not a participant of Z1
- **WHEN** U2 calls `GET /api/parafering/voorstellen/{voorstelId}`
- **THEN** the system SHALL return HTTP 403
- **AND** no voorstel content SHALL be disclosed in the response body

#### Scenario: Non-actor cannot advance the route

- **GIVEN** a voorstel with `status = in_parafering` and a current-step actor M. Bakker
- **WHEN** user P. Janssen calls `POST /api/parafeer-actie` with a `parafered` action for that voorstel
- **THEN** the system SHALL return HTTP 403 with `{"message": "Not authorized for this parafering step"}`
- **AND** no `parafeeractie` SHALL be created
- **AND** the voorstel's `currentStep` SHALL remain unchanged
