## Context

Three declaration surfaces drive OpenRegister's leaf integrations, and Dossiq already uses all three:

1. **`configuration.linkedTypes`** on a schema (read by `Schema::getLinkedTypes()`; consumed by `LinkedEntityService::scanMagicTables()` and the Mail sidebar's link tab) declares which leaves may link objects of that schema. `case` declares `["mail", "calendar", "forms", "photos", "maps", "shares", "decidesk-decisions"]`; `inspectionChecklistRun` declares `["forms", "photos"]`. OpenRegister's `LogDanglingLinkedTypes` repair step logs any value not registered in the integration registry, so additions must be real registry ids.
2. **`configuration.mailObjectTemplate`** (validated by `Schema.php::validateMailObjectTemplate` — object of non-empty property names → scalar values) makes the Mail sidebar's ActionsTab show a create button per schema (`hasCreateTemplate()`), prefilling fields via `{{placeholder}}` substitution from `buildPlaceholders()`: `subject`, `sender`, `senderName`, `date`, `date30`, `datetime`, `preview` (600-char plain-text body), `messageId`, `mailRef`.
3. **Manifest widgets `{"type": "integration", "integrationId": "…"}`** and `component:` sidebar tabs resolved through `leafTab(id)` (`src/integrations/leafTabs.js` → `builtinIntegrations` from `@conduction/nextcloud-vue`). The lib registers 18 leaves in `leaves.js`; each fetches `/apps/openregister/api/objects/{register}/{schema}/{id}/integrations/{integrationId}` with zero per-app glue.

Precedents in this repo: `case-email-integration` (email leaf + `CaseEmailTab`), `migrate-appointments-to-calendar-leaf`, `migrate-inspection-forms-to-forms-leaf`, `migrate-maps-to-maps-leaf`, `consume-decidesk-besluitvorming-leaf`. This change is the same ADR-022 consumption pattern applied to the leaves Dossiq does not yet touch.

## Goals / Non-Goals

**Goals:**
- A caseworker starts a case or complaint from an email in one click, prefilled.
- Case deliberation gets a Talk room surface; case coordination gets a Deck board surface.
- A Nextcloud Forms submission can become a case with its statutory clock started correctly.
- Field inspections get the maps leaf where their coordinates already live.

**Non-Goals:**
- Automatic email→case matching (the concurrent `email-case-matching` change owns the matching job; this change owns only the manual button).
- Any leaf-*side* work: clustering, WMS/layers, cases-on-map overview (OpenRegister `integration-maps`, issue #1316 — stub directory in this repo, authoritative spec in ConductionNL/openregister).
- Deck⇄task sync. `task` records are dispatched and completed by `WorkflowEngineService` against `workflowStepId`; a mirrored Deck card would desynchronise the engine (same argument that refused derived `task` writes in `dossiq-mcp-adoption` design D6).
- Data migration of `hearing.talkRoomUrl` / `hearingSession.videoCallUrl`.

## Decisions

### D1 — mailObjectTemplate: `case` and `complaint`, nothing else

| Schema | Template (property → value) | Why |
|---|---|---|
| `case` | `title` → `{{subject}}`, `description` → `{{preview}}`, `intakeChannel` → `email`, `communicationChannel` → `email`, `startDate` → `{{date}}`, `initiatorDisplayName` → `{{senderName}}` | The citizen-email-starts-a-case flow. All six are real `case` properties. `initiatorSourceId` is **deliberately absent** — it is documented as BSN/KvK/contact-URI and an email address is none of those; prefill nothing rather than pollute an identifying field. `caseType`/`status` are also absent: the create dialog makes the user choose a type, and the intake conventions (initial status per `caseType`) apply on save. |
| `complaint` | `subject` → `{{subject}}`, `description` → `{{preview}}`, `receiptChannel` → `email`, `receiptDate` → `{{date}}` | The Awb ch. 9 klacht arrives by email constantly. `complainant` is **deliberately absent** — it is an embedded citizen record (see `dossiq-mcp-adoption` design D5); the caseworker fills it consciously, the sender's display name is not authoritative identity. |

No other schema gets a template. `task` creation is engine-driven (D6 of the MCP design), `bezwaar` requires a parent case, and everything else is not started from an email in practice. Values are all scalars, so `Schema.php::validateMailObjectTemplate` accepts them at import.

### D2 — Talk: leaf on the case, legacy URL fields stay put

`talk` is added to `case.linkedTypes` and surfaced twice, matching the calendar precedent: a `TalkLeafTab` sidebar tab (`leafTab('talk')` → `CnTalkTab`, `requiredApp: 'spreed'`, empty-state "Talk not available") and a `case-talk` integration widget on the case detail grid. The leaf owns room create/link/unlink.

`hearing.talkRoomUrl` and `hearingSession.videoCallUrl` remain raw strings. Rewiring hearings onto linked Talk rooms renames a wire-visible property, and **a property rename is a data migration** — out of scope here, noted as a follow-up so the debt is recorded rather than smuggled in.

### D3 — Forms intake creates cases through the intake conventions, never a raw insert

The forms leaf today *renders* forms on a case (`FormsLeafTab`, inspection checklists/advice). Intake is the inverse direction: a submission exists before the case does. Two pieces:

1. **Binding:** optional `caseType.intakeFormRef` (string — Nextcloud Forms form hash) declares "submissions of this form open a case of this type". Declared per caseType by an admin; absent means no forms intake for that type.
2. **Creation:** a Forms submission event listener resolves the bound `caseType` and calls `FormsIntakeService`, which creates the case the way the existing intake paths do (`DsoIntakeService` is the precedent): initial status from the caseType, `intakeChannel: "forms"`, `startDate` = submission date, so the materialised `deadline` calculation (`x-openregister-calculations` on `case`: `dateAdd(startDate, @ref.caseType.processingDeadline)`) starts the statutory clock. Submission answers land in the case `description` and the submission is linked via the forms leaf so the full answer set stays reachable.

A raw `ObjectService` insert is exactly the malformed, clock-less case the MCP design refused (`case.create`, D6); the same reasoning binds this change: **the service, not the leaf, creates the case.** When the `forms` app is absent the listener never fires and the binding field is inert.

### D4 — Maps: consumption on the VTH surfaces; everything else is `integration-maps`

Dossiq's per-case maps leaf already shipped (`migrate-maps-to-maps-leaf`, `MapsLeafTab`). The positioning against OpenRegister's `integration-maps` change (cross-repo stub here; authoritative in ConductionNL/openregister #1316):

- **`integration-maps` owns the leaf**: provider behaviour, layers, clustering, and the eventual multi-object overview that would retire `CasesOnMapView`.
- **This change owns Dossiq's declarations only**: `maps` added to `linkedTypes` of `fieldInspection` (carries `gpsLocation`; `register.d/40-mobiel-inspectie-offline.json` — the fragment currently has no `configuration` object at all, so one is created) and `inspectionChecklistRun`, plus the maps leaf tab on their detail pages. VTH field inspections are location-bound records (`location` schema: `latitude`/`longitude`/`addressDesignationId`/`parcelId`; `fieldInspection.gpsLocation`) that today render no map at all.

Nothing here changes leaf behaviour, so there is no overlap to reconcile beyond ordering: this change has no dependency on `integration-maps` landing (it consumes the leaf as it exists today).

### D5 — Deck: coordination, explicitly not workflow

`deck` joins `case.linkedTypes`; `DeckLeafTab` + `case-deck` widget mirror the Talk wiring (`requiredApp: 'deck'`). One hard rule, spec-carried: **no Dossiq code creates, mirrors, or completes Deck cards from `task` records or vice versa.** Tasks belong to `WorkflowEngineService` (`workflowStepId`, materialised `isTerminalStatus`); Deck is for the ad-hoc "who calls the objector, who books the room" coordination around a case. The kanban `WorkflowBoardView` stays the workflow surface. If a sync is ever wanted it is its own change with its own conflict story.

## Risks / Trade-offs

- **[Dangling linkedTypes]** → `talk`, `deck`, `maps` are all registry ids in `leaves.js`; `LogDanglingLinkedTypes` logs (not fails) dangling values, so a typo would be silent in CI — the verification phase greps the declared ids against `leaves.js` and checks the repair-step output on a live instance.
- **[mailObjectTemplate prefill treated as identity]** → Templates deliberately never touch `initiatorSourceId`/`complainant`; the create dialog shows every prefilled field for correction before save.
- **[Forms intake spam]** → Only forms explicitly bound via `intakeFormRef` create cases; the listener validates the binding server-side. An unbound submission does nothing.
- **[Union merge drops new configuration]** → `case` is redefined in `register.d/dso-omgevingsloket.json` (properties only, no `configuration`) — same exposure `dossiq-mcp-adoption` documented; verification reads the imported schema back from OpenRegister, not from the file.
- **[Leaf availability]** → Every leaf tab degrades to the lib's empty state when its `requiredApp` (spreed, deck, forms, maps) is absent; no Dossiq-side guard code needed.

## Migration Plan

1. Schema/JSON declarations (register version bump), `python3 -m json.tool` after every edit.
2. Frontend wiring (registry + manifest), then `FormsIntakeService` + listener.
3. Deploy → re-run register import → verify declarations from OpenRegister → verify each surface in the UI.
4. **Rollback:** revert; all declarations are additive JSON + additive code, nothing depends on them.

## Open Questions

- **Q1** — Should hearings (`hearing`, `hearingSession`) migrate their raw `talkRoomUrl`/`videoCallUrl` strings to linked Talk rooms? Property rename ⇒ data migration; needs its own change.
- **Q2** — Should `intakeFormRef` support field mapping (form question → case property) beyond the description dump? Deferred until a real form demands it.
- **Q3** — When OpenRegister's `integration-maps` ships the multi-object overview, `CasesOnMapView` should be retired in favour of it — tracked there, not here.
