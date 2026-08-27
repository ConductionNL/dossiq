# Leaf Integrations — email, talk, forms-intake, maps, deck (delta)

## Purpose

Close Dossiq's remaining OpenRegister leaf-integration gaps by declaration: `configuration.mailObjectTemplate` on `case` and `complaint` (create-from-email button), `talk` and `deck` on the case detail, a Forms citizen-intake path that starts the statutory clock correctly, and the maps leaf on the VTH inspection surfaces. Consumption only (ADR-022); leaf behaviour stays in OpenRegister / nextcloud-vue.

## ADDED Requirements

### Requirement: REQ-LEAF-101 — Create-from-email templates on case and complaint

Dossiq MUST declare `configuration.mailObjectTemplate` on exactly two schemas in `lib/Settings/dossiq_register.json`: `case` (`title` → `{{subject}}`, `description` → `{{preview}}`, `intakeChannel` → `email`, `communicationChannel` → `email`, `startDate` → `{{date}}`, `initiatorDisplayName` → `{{senderName}}`) and `complaint` (`subject` → `{{subject}}`, `description` → `{{preview}}`, `receiptChannel` → `email`, `receiptDate` → `{{date}}`). Every key MUST be a real property of its schema and every value a scalar (`Schema.php::validateMailObjectTemplate` rejects the schema otherwise). The templates MUST NOT prefill any identifying field: `initiatorSourceId`, `initiatorType`, `requester` (case) and `complainant` (complaint) MUST NOT appear as template keys. This requirement covers the Mail-sidebar **button surface only**; the automatic email→case matching job is the separate `email-case-matching` change.

#### Scenario: Case button appears in the Mail sidebar

- **WHEN** a user with access to the Dossiq register opens an email in the Mail sidebar's Actions tab
- **THEN** the `case` and `complaint` schemas SHALL be offered as create targets (`hasCreateTemplate()` returns true for both)
- **AND** no other Dossiq schema SHALL be offered

#### Scenario: Prefill from the email, identity left blank

- **WHEN** the user clicks create-case on an email with subject "Kapotte lantaarnpaal Dorpsstraat"
- **THEN** the create dialog SHALL open with `title` = "Kapotte lantaarnpaal Dorpsstraat", `description` = the 600-char plain-text preview, `intakeChannel` = "email", and `startDate` = the email's date
- **AND** `initiatorSourceId` SHALL be empty (never prefilled from an email address)

#### Scenario: Import accepts the templates

- **WHEN** the Dossiq registers are imported into OpenRegister
- **THEN** `validateMailObjectTemplate` SHALL raise no error for `case` or `complaint`

### Requirement: REQ-LEAF-102 — Talk deliberation rooms on the case detail

Dossiq MUST add `talk` to `case.configuration.linkedTypes`, register a `TalkLeafTab` in `src/registry.js` resolved via `leafTab('talk')`, and add a `{"id": "case-talk", "type": "integration", "integrationId": "talk"}` widget (plus layout entry) and the sidebar tab to the case detail page in `src/manifest.json`. The leaf owns room create/link/unlink; Dossiq MUST NOT add bespoke Talk API code. `hearing.talkRoomUrl` and `hearingSession.videoCallUrl` are unchanged by this change.

#### Scenario: Deliberation room from the case

- **WHEN** a caseworker opens a case detail with the Talk app (`spreed`) enabled
- **THEN** the Talk integration surface SHALL render and allow creating or linking a deliberation room for this case

#### Scenario: Graceful degradation without Talk

- **WHEN** `spreed` is not enabled
- **THEN** the surface SHALL show the library's "Talk not available" empty state and no error

### Requirement: REQ-LEAF-103 — Forms citizen intake creates cases through the intake conventions

Dossiq MUST add an optional `intakeFormRef` string property to the `caseType` schema, and a Forms submission listener + `FormsIntakeService` (new, `lib/Service/FormsIntakeService.php`) that, for submissions of a bound form, creates a `case` of that `caseType` with the caseType's initial status, `intakeChannel: "forms"`, and `startDate` set to the submission date — so the materialised `deadline` calculation (`dateAdd(startDate, @ref.caseType.processingDeadline)`) starts the statutory clock. The case MUST be created through `FormsIntakeService`, never by a raw `ObjectService` insert from the frontend. Submissions of forms not bound by any `caseType.intakeFormRef` MUST create nothing.

#### Scenario: Bound form submission opens a case

- **WHEN** a citizen submits the Forms form whose hash matches a caseType's `intakeFormRef`
- **THEN** a case of that caseType SHALL exist with the caseType's initial status, `intakeChannel` = "forms", and a `deadline` materialised from `startDate` + `caseType.processingDeadline`
- **AND** the submission SHALL be reachable from the case via the forms leaf

#### Scenario: Unbound form submission is inert

- **WHEN** a form whose hash matches no `intakeFormRef` receives a submission
- **THEN** no case SHALL be created and no error SHALL be logged above info level

#### Scenario: Forms app absent

- **WHEN** the `forms` app is not enabled
- **THEN** the listener SHALL never fire and caseType editing SHALL still accept (and simply store) `intakeFormRef`

### Requirement: REQ-LEAF-104 — Maps leaf on the VTH inspection surfaces

Dossiq MUST add `maps` to the `configuration.linkedTypes` of `fieldInspection` (`lib/Settings/register.d/40-mobiel-inspectie-offline.json` — creating the `configuration` object, which the fragment currently lacks) and of `inspectionChecklistRun` (`lib/Settings/dossiq_register.json`, alongside its existing `["forms", "photos"]`), and surface the maps leaf tab on both detail pages. Leaf-side behaviour (providers, layers, clustering, the multi-object overview) is owned by OpenRegister's `integration-maps` change and MUST NOT be duplicated or modified here; the existing per-case `MapsLeafTab` and the `CasesOnMapView` overview are unchanged.

#### Scenario: Inspection location on the map

- **WHEN** an inspector opens a `fieldInspection` detail whose `gpsLocation` is set
- **THEN** the maps leaf SHALL render the inspection's location marker

#### Scenario: No overlap with integration-maps

- **WHEN** this change is fully applied
- **THEN** no file under OpenRegister's maps provider or the nextcloud-vue maps leaf SHALL have been modified by Dossiq, and `CasesOnMapView` SHALL be byte-identical to before

### Requirement: REQ-LEAF-105 — Deck coordination boards, decoupled from workflow tasks

Dossiq MUST add `deck` to `case.configuration.linkedTypes` and surface a `DeckLeafTab` (`leafTab('deck')`) plus a `{"id": "case-deck", "type": "integration", "integrationId": "deck"}` widget on the case detail. Dossiq MUST NOT create, mirror, update, or complete Deck cards from `task` records, nor `task` records from Deck cards: `task` lifecycle belongs exclusively to `WorkflowEngineService` (`workflowStepId`, materialised `isTerminalStatus`), and the kanban `WorkflowBoardView` remains the workflow surface.

#### Scenario: Ad-hoc board on a case

- **WHEN** a caseworker opens a case detail with the Deck app enabled
- **THEN** the Deck integration surface SHALL allow linking or creating a board for ad-hoc case coordination

#### Scenario: Tasks are not mirrored

- **WHEN** a `task` record is created or completed by the workflow engine
- **THEN** no Deck card SHALL be created or changed by Dossiq code

### Requirement: REQ-LEAF-106 — Every declared linkedType resolves to a registered leaf

Every value Dossiq declares in any schema's `configuration.linkedTypes` MUST be an id registered in the integration registry (`nextcloud-vue/src/integrations/builtin/leaves.js` or a cross-app registration such as `decidesk-decisions`), because OpenRegister's `LogDanglingLinkedTypes` repair step only *logs* dangling values — a typo fails silently.

#### Scenario: Repair step reports no dangling types

- **WHEN** the `LogDanglingLinkedTypes` repair step runs after import on an instance with this change applied
- **THEN** it SHALL report no Dossiq schema with a dangling linkedTypes value
