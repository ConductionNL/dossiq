# Proposal: consume-decidesk-besluitvorming-leaf

## Summary

Surface decidesk's **Besluitvorming** (decision-making) on the dossiq **case-detail** page as an OpenRegister integration leaf, and retire dossiq's standalone **Besluitvorming** navigation group (`Voorstellen`, `Advies`, `Agenda`). The directive is "decidesk owns it; dossiq shows a leaf" — achieved by configuration, not custom code.

decidesk registers a `decidesk-decisions` provider on the shared OpenRegister integration registry (`window.OCA.OpenRegister.integrations`, ADR-019) via a global init script that loads on every Nextcloud page. dossiq consumes that provider as a sidebar tab on the case detail; the leaf lists proposals/advice/decisions linked to the case via decidesk's `subjectId == case.uuid` back-reference and offers "Create proposal" + "Open in decidesk". The former `Voorstellen` / `Advies` / `Agenda` top-level nav entries are removed; their pages stay routable for deep links and e2e (ADR-044 hard invariant).

## Motivation

dossiq historically owned a `voorstel` (proposal) and `adviesAanvraag` (advice) model with its own `Besluitvorming` nav group. Per the fleet decision (ADR-019 / ADR-022, "apps consume OR integration leaves"), decision-making is owned by **decidesk** and surfaced wherever it is needed via a single shared leaf. A dossiq case is the canonical consumer. Keeping a parallel `Besluitvorming` nav in dossiq duplicates what now lives on the case detail and splits the decision record across two apps. Consuming the leaf consolidates the decision surface onto the case, removes duplicate navigation, and lets decidesk own the model.

## Affected Projects

- [x] Project: dossiq

## What changes

1. **Case-detail leaf.** A new `besluitvorming` sidebar tab on the `CaseDetail` page (manifest `config.sidebarTabs`) renders the `decidesk-decisions` leaf. A thin wrapper component (`BesluitvormingLeafTab`) resolves the registered provider's `tab` from the live OR registry at render time and forwards the case `{ register, schema, objectId }` context that `CnObjectSidebar` injects. No decidesk logic is re-implemented; dossiq only wires the registry id. When decidesk's leaf is not deployed the tab renders a quiet unavailable notice instead of breaking.

2. **Nav retirement.** `Voorstellen`, `Advies` and `BesluitvormingAgenda` are added to `menu-layout.json#removals` and their relocations into `BesluitvormingGroup` are dropped, so the empty group shell is pruned. The `voorstel` / `adviesAanvraag` schemas and their data are untouched; the `/voorstellen`, `/voorstellen/:id`, `/advice`, `/advice/:id`, `/besluitvorming/agenda` and `/besluitvorming/vergaderingen/:id` routes stay registered for deep links and e2e.

3. **voorstel → decidesk migration (documented, already implemented).** The data migration from dossiq's `voorstel` records to decidesk's decision model is **already shipped** by the active `dossiq-delegate-remaining-decisions-to-decidesk` change: `AdviceDelegationService::raiseVoorstelBesluit()` raises a decidesk Decision (`decisionType: report-adoption`) with `subjectId = voorstel.uuid`, `externalReference = case`, `subjectLabel = onderwerp`, and writes the resulting `decisionRef` back onto the voorstel. It is idempotent, data-loss-safe (terminal/already-linked records are kept as the authoritative historical record), gated behind the `LinkInFlightRemainingDecisionsRepair` repair step (runs on `occ upgrade`), and warns-and-skips when the decidesk leaf is unavailable rather than failing the migration. This change does **not** add a second migration mechanism — it points at the existing one and records the production caveat (see below).

## Production caveats (decidesk-side, outside dossiq scope)

Two decidesk-side conditions gate the *live* leaf surface. Neither blocks the dossiq wiring (which degrades gracefully to an "Besluitvorming unavailable" notice when the provider is absent), but both must be resolved on the decidesk release for the real decidesk surface to render and for create-proposal to persist.

### 1. decidesk leaf init-script not yet enqueued on the deployed/development tree (RENDER blocker)

The `decidesk-decisions` provider is registered onto `window.OCA.OpenRegister.integrations` by decidesk's `decidesk-integration-init.js` global bundle, which decidesk enqueues with `\OCP\Util::addInitScript('decidesk', 'decidesk-integration-init')` in `Application::boot()`. That wiring lives in decidesk commit `d4940267` ("feat(integrations): add Besluitvorming decisions integration leaf", PR #100) — **which is on its own feature branch and is NOT merged into decidesk `development`, nor present on the currently checked-out / deployed decidesk tree.** The deployed decidesk has the orphan `js/decidesk-integration-init.js` *artifact* but no `addInitScript` call to load it, so the provider never registers at runtime. Verified by page inspection: `/index.php/apps/dossiq/` loads `openregister-integration-global.js` and `openconnector-integration.js` (openconnector wires its leaf the same way at `lib/AppInfo/Application.php` via `Util::addInitScript('openconnector', 'openconnector-integration')`) but emits no `decidesk-integration-init` script. **Action: merge decidesk PR #100 to `development` and deploy.** Until then dossiq's leaf tab renders the graceful unavailable fallback.

### 2. `decisionType` mis-import `format: uuid` (WRITE blocker — FIXED on this dev instance)

The deployed decidesk OR register (#18, schema id 96 `decision`, uuid `fade27fc-…`) had its `decisionType` property mis-imported with a `format: uuid` constraint, so decision **writes** 422'd on this instance — affecting all decision creation, not just the leaf. The decidesk **source** register (`lib/Settings/decidesk_register.json` → `components.schemas.Decision.properties.decisionType`) is correct: `type: string` with a 10-value enum (`motion … report-adoption … meeting-outcome`) and no `format`. OpenRegister's `ConfigurationService::importFromApp` is **non-destructive for existing schema properties even with `force: true`** (verified: a forced `POST /apps/decidesk/api/settings/load` returned `success` but left `format: uuid` in place), so the bad constraint will not self-heal via re-import. **Fixed on this dev instance** by a single-property data repair on schema 96 (`jsonb_set(properties, '{decisionType}', <canonical from decidesk source>)`), after which a `POST /api/objects/18/96` with `decisionType: report-adoption, lifecycle: proposed, subjectId: <case-uuid>` returns **HTTP 201** (previously 422). **Production action:** apply the same one-property repair to schema #96 (or add a decidesk repair step that force-overwrites `decisionType`), since `importFromApp(force:true)` alone will not correct it. Production has real `voorstel` data that needs migrating (via the existing `AdviceDelegationService::raiseVoorstelBesluit` + `LinkInFlightRemainingDecisionsRepair`) once this write path is restored there.
