# Close Procest's remaining OpenRegister leaf-integration gaps

## Why

OpenRegister ships ~18 app-agnostic integration leaves (calendar, contacts, email, talk, bookmarks, collectives, maps, photos, activity, analytics, cospend, deck, flow, forms, polls, time-tracker, shares, openproject — `nextcloud-vue/src/integrations/builtin/leaves.js`), consumed through three declaration surfaces: schema `configuration.linkedTypes` (which schemas the Mail sidebar and `LinkedEntityService` may link), schema `configuration.mailObjectTemplate` (the create-from-email button in the Mail sidebar's ActionsTab), and manifest widgets `{"type": "integration", "integrationId": "…"}` plus `component:` sidebar tabs resolved via `leafTab()` (`src/integrations/leafTabs.js`, ADR-022).

Procest already consumes a lot of this. The `case` schema declares `linkedTypes: ["mail", "calendar", "forms", "photos", "maps", "shares", "decidesk-decisions"]`; the case detail renders `files`, `notes`, `contacts`, `calendar` and `decidesk-decisions` integration widgets (`src/manifest.json`) and `CaseEmailTab`, `CalendarLeafTab`, `FormsLeafTab`, `PhotosLeafTab`, `MapsLeafTab`, `CaseNotesTab`, `VersionHistoryLeafTab` sidebar tabs (`src/registry.js`); `inspectionChecklistRun` declares `linkedTypes: ["forms", "photos"]`.

What is missing, verified against the checkout:

- **No `mailObjectTemplate` anywhere in Procest** (`grep -rn mailObjectTemplate lib/` → zero hits). A caseworker reading a citizen email in Mail can *link* it to an existing case, but cannot *start* a case (or a complaint) from it — pipelinq (lead) and shillinq (invoice) already ship this button.
- **No Talk leaf.** `hearing.talkRoomUrl` and `hearingSession.videoCallUrl` are raw pasted strings; case deliberation has no room surface at all.
- **Forms leaf is render-only.** `FormsLeafTab` shows checklist/advice forms on a case; there is no citizen-intake path where a Nextcloud Forms submission becomes a case with its statutory clock started.
- **Maps stops at the `case` schema.** The VTH inspection surfaces — `fieldInspection` (which carries `gpsLocation`) and `inspectionChecklistRun` — have no maps leaf, even though field inspections are the most location-bound records Procest owns.
- **No Deck leaf.** Case workers have no ad-hoc coordination board attached to a case.

## What Changes

- **Create-case-from-email.** Declare `configuration.mailObjectTemplate` on `case` (title/description/intakeChannel/startDate/initiatorDisplayName/communicationChannel prefilled from the email placeholders) and on `complaint` (subject/description/receiptChannel/receiptDate) in `lib/Settings/procest_register.json`. This is the **button surface only**: the automatic email→case *matching job* is the separate, concurrently-authored `email-case-matching` change and is explicitly out of scope here.
- **Talk deliberation rooms.** Add `talk` to `case.linkedTypes`; register a `TalkLeafTab` (`leafTab('talk')` → `CnTalkTab`, `requiredApp: spreed`) in `src/registry.js` and a `{"type": "integration", "integrationId": "talk"}` widget on the case detail in `src/manifest.json`. Hearing schemas keep their existing URL fields (documented as legacy; see design).
- **Forms citizen intake → case.** New optional `caseType.intakeFormRef` property binding a Nextcloud Forms form to a case type, plus a server-side `FormsIntakeService` that turns a submission into a case **through the existing intake conventions** (initial status, `intakeChannel: "forms"`, deadline calculation from `caseType.processingDeadline`) — never a raw object insert.
- **Maps on the VTH inspection surfaces.** Add `maps` to the `linkedTypes` of `fieldInspection` (`register.d/40-mobiel-inspectie-offline.json`) and `inspectionChecklistRun`, and surface the maps leaf on their detail pages. The multi-case map overview (`CasesOnMapView`) and any leaf-side clustering/layers work stay with OpenRegister's `integration-maps` change (cross-repo stub in this repo; authoritative in ConductionNL/openregister, issue #1316) — this change adds **consumption only** and duplicates none of it.
- **Deck coordination boards.** Add `deck` to `case.linkedTypes`; `DeckLeafTab` + `{"type": "integration", "integrationId": "deck"}` widget on the case detail. Deck cards are ad-hoc coordination aids and MUST NOT mirror or replace engine-driven `task` records (the kanban `WorkflowBoardView` remains the workflow surface).

## Capabilities

### New Capabilities

- `leaf-integrations`: the declaration set (linkedTypes, mailObjectTemplate, manifest integration widgets, leaf tabs) that closes the email/talk/forms/maps/deck gaps.

### Modified Capabilities

_None._ Existing leaf consumption (calendar, forms-render, photos, maps-on-case, notes, email link tab, version-history, decidesk-decisions) is untouched.

## Impact

- **Schemas / registers:** `lib/Settings/procest_register.json` (`case`: +`talk`, +`deck` in `linkedTypes`, +`mailObjectTemplate`; `complaint`: +`mailObjectTemplate`; `caseType`: +`intakeFormRef` property; `inspectionChecklistRun`: +`maps` in `linkedTypes`), `lib/Settings/register.d/40-mobiel-inspectie-offline.json` (`fieldInspection`: +`configuration.linkedTypes: ["maps"]`). Register version bump so the import repair step is not a no-op.
- **PHP:** new `lib/Service/FormsIntakeService.php` + a Forms submission listener registered in `lib/AppInfo/Application.php`; no existing service changes.
- **Frontend:** `src/registry.js` (+`TalkLeafTab`, +`DeckLeafTab` via `leafTab()`), `src/manifest.json` (case detail: +2 integration widgets + layout entries + 2 sidebar tabs; fieldInspection/inspectionChecklistRun detail: +maps).
- **Related changes:** `email-case-matching` (concurrent, owns the matching job — referenced, not touched), OpenRegister `integration-maps` (cross-repo, owns leaf-side maps work), `migrate-maps-to-maps-leaf` / `migrate-inspection-forms-to-forms-leaf` / `case-email-integration` (shipped precedents this change extends).
- **Out of scope:** automatic email→case matching (`email-case-matching`); leaf-side clustering, WMS layers, or the cases-on-map overview (`integration-maps`); migrating `hearing.talkRoomUrl` / `hearingSession.videoCallUrl` data; any Deck⇄task synchronisation; portal (Portaliq) intake — ADR-046 moved the citizen portal out of Procest, and this change binds only *internal-instance* Forms.
