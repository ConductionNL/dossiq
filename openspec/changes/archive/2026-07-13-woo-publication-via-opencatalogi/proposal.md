# Proposal: woo-publication-via-opencatalogi

kind: code — new capability. Bridges procest's existing WOO besluit pipeline
(`WOODecisionService`, `WOODocumentAssessmentService`, `WOORedactionService`) to
OpenCatalogi, the sibling app that owns publication/DCAT catalogs per
ADR-Leaf-First, so an assembled WOO besluit can actually be published to a
public reading room instead of only existing as an internal OpenRegister
object.

## Why

Municipalities average 143 days to answer a WOO request against the 42-day
statutory term (28 days + one 14-day verdaging), and proactive/active
publication is part of what the WOO requires. Procest already has the full
assessment pipeline — per-document disclosure classification with mandatory
weigeringsgronden (`WOODocumentAssessmentService`), Docudesk-driven redaction
(`WOORedactionService`), and formal besluit assembly (`WOODecisionService`,
wired to `WOOAssessmentController::createDecision`) — but nothing after
`assembleDecision()` ever leaves procest. The `woo-case-type` spec's own
"Publication to reading room" requirement is marked `status: done` from a
2026-06-13 reverse-sync, but no code implementing it exists at HEAD (verified:
`grep -rniI "wooPublicat|readingRoom|leeszaal" lib/ src/` returns nothing).
Every WOO besluit dead-ends at "Besluit" — stage 7 "Publicatie" has no handler.

OpenCatalogi is the sibling app that already owns the publication/DCAT surface
(catalogi, publications, DIWOO sitemaps, TOOI/DiWoo vocabulary binding — see
`openspec/specs/woo-compliance/spec.md` in that repo). Per ADR-Leaf-First,
procest must publish *through* OpenCatalogi's publication model, not grow a
second bespoke publication surface.

**Binding investigated against `origin/development` HEAD in both repos** (no
local checkout of OpenCatalogi was assumed to be current — its own
`publications#` controller (`PublicationsController.php`) is **read-only**
(`index`/`show`/`uses`/`used`/`attachments`/`download`); it exposes no
create/update endpoint of its own. OpenCatalogi's own frontend
(`src/store/modules/object.js`) and backend (`PublicationService::getObjectService()`)
both create/update/withdraw publications the same way: directly against
**OpenRegister's generic Objects REST API**
(`/index.php/apps/openregister/api/objects/{register}/{schema}[/{id}]`), using
the `publication` register (schema `publication` for the publication itself,
`document` for attached documents, `files#create` for file bytes) that ships
as OpenCatalogi's default bundle
(`lib/Settings/publication_register.json`). "Publish" and "withdraw" are not
separate endpoints either — they are `publicatiedatum`/`depublicatiedatum`
date fields on the publication object (a date in the past = live; a
depublication date in the past = withdrawn). This change follows that exact,
confirmed binding rather than inventing an OpenCatalogi-specific write route.
There is no existing procest precedent for in-process PHP consumption of an
OpenCatalogi *service* class, so this change follows the same-instance-peer
pattern `LibresignApiClient` established for LibreSign: a thin
`IClientService`-based HTTP boundary, isolated to one class.

OpenCatalogi's real DIWOO informatiecategorie vocabulary has **17** categories
(`infocat001`..`infocat017`, `lib/Service/TooiVocabularyService.php`), not the
11 this task's brief assumed. A WOO besluit maps cleanly onto exactly one of
them: `infocat014` "Woo-verzoeken en -besluiten"
(`https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532`). The mapper
built here is a lookup table keyed by decision type (not a hard-coded
constant inline), so a future change can add e.g. `infocat016`
"Beschikkingen" for `subsidie`-domain decisions without touching call sites.

## What Changes

- **NEW**: `WooPublicationService` (`lib/Service/WooPublicationService.php`) —
  builds the publication payload from an assembled WOO besluit + its
  assessments (title, summary, category, decision date, case reference,
  **redacted-only** document set), creates the publication (+ attached
  documents) in OpenCatalogi's register via `OpenCatalogiApiClient`, and
  writes the returned publication id/url/status back onto the procest
  `decision` object through a single `ObjectService::saveObject()` call.
  Also implements `withdraw()` (sets `depublicatiedatum`).
- **NEW**: `WooCategoryMapper` (`lib/Service/WooPublication/WooCategoryMapper.php`)
  — the one testable place mapping a procest decision/case onto a DIWOO
  informatiecategorie.
- **NEW**: `OpenCatalogiApiClient` (`lib/Service/WooPublication/OpenCatalogiApiClient.php`)
  — thin `IClientService` HTTP boundary to OpenRegister's Objects API for the
  register/schema OpenCatalogi owns, following `LibresignApiClient`'s pattern.
  Feature-gated on `IAppManager::isEnabledForUser('opencatalogi')` — no hard
  dependency; absent app/config reports `available: false` with an admin
  hint rather than throwing into the case flow.
- **MODIFIED**: `WOOAssessmentController` — new `publishDecision()` /
  `withdrawPublication()` endpoints. **Not** a `Transitions/ActionHandlerInterface`
  handler: verified the `woo-verzoek.json` template's eight status types carry
  no `actions`/`automaticActions` config (unlike `besluitvorming-workflow`,
  which explicitly drives `BesluitvormingPublishHandler` off the engine) — the
  WOO flow at HEAD is entirely explicit-controller-driven
  (`bulkAssess`/`extendDeadline`/`createDecision` are all plain REST calls with
  no frontend caller yet either), so the publish trigger follows that same
  shape for consistency. Documented as an explicit choice in `design.md`.
- **NEW**: `src/services/wooPublicationApi.js` — frontend API client for the
  two new endpoints.
- **MODIFIED**: `src/views/cases/components/DocumentAssessmentPanel.vue` —
  adds a minimal publish action + publication status/link section (embeds a
  new small `WooPublicationPanel.vue`, mirroring the existing
  `BesluitPublicatiePanel.vue` pattern). No new page/route.
- **NEW**: `src/views/cases/components/WooPublicationPanel.vue`.
- Settings: two new app-config keys with safe defaults
  (`getWooPublicationConfigValue()` on `SettingsService`, mirroring the
  existing `getKccConfigValue()` pattern) — `woo_publication_register`
  (default `publication`) and `woo_publication_document_schema` (default
  `document`), overridable if an instance customizes OpenCatalogi's bundle.

## Impact

- Affected specs: `woo-publication-via-opencatalogi` (new capability spec);
  cross-references `woo-case-type`'s existing (unimplemented)
  "Publication to reading room" requirement.
- Affected code: `lib/Service/WooPublicationService.php` (new),
  `lib/Service/WooPublication/*` (new), `lib/Service/SettingsService.php`
  (additive), `lib/Controller/WOOAssessmentController.php` (additive),
  `appinfo/routes.php` (additive), `src/services/wooPublicationApi.js` (new),
  `src/views/cases/components/DocumentAssessmentPanel.vue` (additive),
  `src/views/cases/components/WooPublicationPanel.vue` (new).
- No new Composer dependencies. No changes to OpenCatalogi or OpenRegister —
  procest is a pure HTTP consumer of an already-shipped, confirmed API.
