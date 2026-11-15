# Leaf Integrations — email, talk, forms-intake, maps, deck (delta)

## Purpose

Close Dossiq's remaining OpenRegister leaf-integration gaps by declaration: `configuration.mailObjectTemplate` on `case` and `complaint` (create-from-email button), `deck` on the case detail, `talk` on the case record, a Forms citizen-intake path that starts the statutory clock correctly, and the maps leaf on the VTH inspection surfaces. Consumption only (ADR-022); leaf behaviour stays in OpenRegister / nextcloud-vue.

## ADDED Requirements

### Requirement: REQ-LEAF-101 — Create-from-email templates on case and complaint

Dossiq MUST declare `configuration.mailObjectTemplate` on exactly two schemas in `lib/Settings/dossiq_register.json`: `case` (`title` → `{{subject}}`, `description` → `{{preview}}`, `intakeChannel` → `email`, `startDate` → `{{date}}`, `initiatorDisplayName` → `{{senderName}}`) and `complaint` (`subject` → `{{subject}}`, `description` → `{{preview}}`, `receiptChannel` → `email`, `receiptDate` → `{{date}}`). Every key MUST be a real property of its schema and every value a scalar (`Schema.php::validateMailObjectTemplate` rejects the schema otherwise). The templates MUST NOT prefill any identifying field: `initiatorSourceId`, `initiatorType`, `requester` (case) and `complainant` (complaint) MUST NOT appear as template keys.

Both schemas MUST also carry `mail` in `configuration.linkedTypes`. The Mail sidebar builds its schema list by filtering on `linkedTypes.includes('mail')` and draws the create button per schema in that list, so a template without the sentinel is a button that never appears.

`communicationChannel` is NOT a template key, though the change's proposal named it: the property is `format: uri`, and the literal `email` is not a URI.

This requirement covers the Mail-sidebar **button surface only**; the automatic email→case matching job is the separate `email-case-matching` change.

#### Scenario: Case button appears in the Mail sidebar
@e2e tests/e2e/leaf-integrations.spec.ts

- **WHEN** a user with access to the Dossiq register opens an email in the Mail sidebar's Actions tab
- **THEN** the `case` and `complaint` schemas SHALL be offered as create targets
- **AND** no other Dossiq schema SHALL be offered

#### Scenario: Prefill from the email, identity left blank
@e2e exclude the prefill is the Mail sidebar's own substitution over an envelope this repository does not own; what dossiq decides is the template, asserted in `tests/Unit/LeafIntegrationDeclarationsTest.php::testNoTemplatePrefillsAnIdentity` and read back from the store in the scenario above

- **WHEN** the user clicks create-case on an email with subject "Kapotte lantaarnpaal Dorpsstraat"
- **THEN** the create dialog SHALL open with `title` = "Kapotte lantaarnpaal Dorpsstraat", `description` = the 600-char plain-text preview, `intakeChannel` = "email", and `startDate` = the email's date
- **AND** `initiatorSourceId` SHALL be empty (never prefilled from an email address)

#### Scenario: Import accepts the templates
@e2e tests/e2e/leaf-integrations.spec.ts

- **WHEN** the Dossiq registers are imported into OpenRegister
- **THEN** both templates SHALL be readable back from the schema store

### Requirement: REQ-LEAF-102 — Talk is linkable to a case

Dossiq MUST add `talk` to `case.configuration.linkedTypes`, so a Talk conversation can be linked to a case through OpenRegister's own linking surfaces.

Dossiq MUST NOT add a second Talk surface to the case page. One already shipped: `live-conversation-on-the-case` placed a `case-conversations-pane` section in the Communication panel, which starts a room through `OCP\Talk\IBroker`, records what the case held, and declares the case major. A leaf tab beside it would list rooms from OpenRegister's link table while the pane lists rooms from `case.conversations`, so the same question would have two answers that never agree.

`hearing.talkRoomUrl` and `hearingSession.videoCallUrl` are unchanged by this change.

#### Scenario: A conversation can be linked to a case
@e2e tests/e2e/leaf-integrations.spec.ts

- **WHEN** the Dossiq registers are imported
- **THEN** `case.configuration.linkedTypes` SHALL carry `talk`, and the leaves it already carried SHALL still be there

#### Scenario: The case page keeps one Talk surface
@e2e exclude structural; asserted in `tests/Unit/LeafIntegrationDeclarationsTest.php` and by the absence of a `talk` widget in `src/manifest.json`

- **WHEN** a caseworker opens a case detail
- **THEN** exactly one surface SHALL offer to start or open a conversation

### Requirement: REQ-LEAF-103 — Forms citizen intake creates cases through the intake conventions

Dossiq MUST add an optional `intakeFormRef` string property to the `caseType` schema, and a Forms submission listener + `FormsIntakeService` (`lib/Service/FormsIntakeService.php`) that, for submissions of a bound form, creates a `case` of that `caseType` with the caseType's initial status, `intakeChannel: "forms"`, and `startDate` set to the submission date — so the materialised `deadline` calculation (`dateAdd(startDate, @ref.caseType.processingDeadline)`) starts the statutory clock. The case MUST be created through `FormsIntakeService`, never by a raw `ObjectService` insert from the frontend. Submissions of forms not bound by any `caseType.intakeFormRef` MUST create nothing.

The service MUST re-check the form hash on every row the store hands back, rather than trusting the filter. A store that does not recognise a filter key answers the whole register, confidently and with no error, and the first row of that answer would open a case of a type nobody bound.

Neither the listener nor the service MAY import or type hint a class from `OCA\Forms`. The app is optional, and a type hint on a class the instance does not have is a fatal when the container builds the listener rather than a feature that is quietly missing.

#### Scenario: Bound form submission opens a case
@e2e exclude no Forms app is installed on the e2e rig, so a submission cannot be made; covered by `tests/Unit/Service/FormsIntakeServiceTest.php::testABoundFormOpensACaseOfThatType` and `::testTheClockStartsAtTheSubmission`

- **WHEN** a citizen submits the Forms form whose hash matches a caseType's `intakeFormRef`
- **THEN** a case of that caseType SHALL exist with the caseType's initial status, `intakeChannel` = "forms", and a `startDate` of the submission date

#### Scenario: Unbound form submission is inert
@e2e exclude same rig limit; covered by `tests/Unit/Service/FormsIntakeServiceTest.php::testAnUnboundFormOpensNothing` and `::testAStoreThatIgnoresTheFilterOpensNothing`

- **WHEN** a form whose hash matches no `intakeFormRef` receives a submission
- **THEN** no case SHALL be created and no error SHALL be logged above info level

#### Scenario: Forms app absent
@e2e tests/e2e/leaf-integrations.spec.ts

- **WHEN** the `forms` app is not enabled
- **THEN** the listener SHALL never be registered and caseType editing SHALL still accept and store `intakeFormRef`

### Requirement: REQ-LEAF-104 — Maps leaf on the VTH inspection surfaces

Dossiq MUST add `maps` to the `configuration.linkedTypes` of `fieldInspection` (`lib/Settings/register.d/40-mobiel-inspectie-offline.json` — creating the `configuration` object, which the fragment currently lacks) and of `inspectionChecklistRun` (`lib/Settings/dossiq_register.json`, alongside its existing `["forms", "photos"]`). Leaf-side behaviour (providers, layers, clustering, the multi-object overview) is owned by OpenRegister's `integration-maps` change and MUST NOT be duplicated or modified here; the existing per-case `MapsLeafTab` and the `CasesOnMapView` overview are unchanged.

The declaration is the whole of this requirement. Neither schema has a dossiq detail page to put a tab on, so the leaf surfaces on OpenRegister's own object page; giving `fieldInspection` a page of its own is a new surface and belongs to `no-schema-without-a-surface`.

#### Scenario: Inspection location on the map
@e2e tests/e2e/leaf-integrations.spec.ts

- **WHEN** the Dossiq registers are imported
- **THEN** both inspection schemas SHALL declare the maps leaf, and the checklist run SHALL keep the two leaves it already had

#### Scenario: No overlap with integration-maps
@e2e exclude structural; this change touches no file under OpenRegister or nextcloud-vue, which the PR diff shows

- **WHEN** this change is fully applied
- **THEN** no file under OpenRegister's maps provider or the nextcloud-vue maps leaf SHALL have been modified by Dossiq, and `CasesOnMapView` SHALL be byte-identical to before

### Requirement: REQ-LEAF-105 — Deck coordination boards, decoupled from workflow tasks

Dossiq MUST add `deck` to `case.configuration.linkedTypes` and surface a `{"id": "case-deck", "type": "integration", "integrationId": "deck"}` widget on the case detail, as a section of the Work panel beside the tasks. Dossiq MUST NOT create, mirror, update, or complete Deck cards from `task` records, nor `task` records from Deck cards: `task` lifecycle belongs exclusively to `WorkflowEngineService` (`workflowStepId`, materialised `isTerminalStatus`), and the kanban `WorkflowBoardView` remains the workflow surface.

It is a section rather than a body widget with a layout entry, which the design named: the case body is a tab strip and its grid is full, and a card board is work.

#### Scenario: Ad-hoc board on a case
@e2e tests/e2e/leaf-integrations.spec.ts

- **WHEN** a caseworker opens a case detail with the Deck app enabled
- **THEN** the Deck integration surface SHALL allow linking or creating a board for ad-hoc case coordination

#### Scenario: Tasks are not mirrored
@e2e exclude structural; asserted in `tests/Unit/LeafIntegrationDeclarationsTest.php::testNoCodePathLinksTasksToDeck`, which greps `lib/` for the shapes a real Deck call takes and asserts the searched-file count

- **WHEN** a `task` record is created or completed by the workflow engine
- **THEN** no Deck card SHALL be created or changed by Dossiq code

### Requirement: REQ-LEAF-106 — Every declared linkedType resolves to a registered leaf, or is a named sentinel

Every value Dossiq declares in any schema's `configuration.linkedTypes` MUST be an id registered in the integration registry (`nextcloud-vue/src/integrations/builtin/`, or a cross-app registration such as `decidesk-decisions`), because OpenRegister's `LogDanglingLinkedTypes` repair step only *logs* dangling values — a typo fails silently.

There is exactly one exception, and it MUST be declared as such rather than removed: `mail`. The leaf and its PHP provider are both called `email`. `mail` is a separate literal that OpenRegister's Mail sidebar filters on, so it is a sentinel in the same list and in a different vocabulary. Removing it on the grounds that it resolves to no leaf would silently take away both the link button and the create button.

#### Scenario: Every value resolves or is named
@e2e exclude structural; asserted in `tests/Unit/LeafIntegrationDeclarationsTest.php::testEveryLinkedTypeResolves`, which reads the ids out of the installed library rather than from a list in the test

- **WHEN** every `linkedTypes` value in this repository is checked against the registry
- **THEN** each SHALL be a registered leaf id, a cross-app registration, or the named `mail` sentinel
