# Tasks: consume-decidesk-besluitvorming-leaf

## 1. Case-detail leaf

- [x] 1.1 Add `BesluitvormingLeafTab.vue` wrapper that resolves the `decidesk-decisions` provider's `tab` from `window.OCA.OpenRegister.integrations` at render time and forwards `{ register, schema, objectId }` as integration context; renders an unavailable notice when the leaf is absent.
- [x] 1.2 Register `BesluitvormingLeafTab` in `src/registry.js` as a `kind: 'page'` entry.
- [x] 1.3 Add a `besluitvorming` entry to `CaseDetail.config.sidebarTabs` in `src/manifest.json` (label "Besluitvorming", `component: "BesluitvormingLeafTab"`).

## 2. Retire the Besluitvorming nav

- [x] 2.1 Remove the `Voorstellen` / `Advice` / `BesluitvormingAgenda` relocations into `BesluitvormingGroup` in `src/menu-layout.json` (the now-empty group shell is auto-pruned).
- [x] 2.2 Add `BesluitvormingGroup`, `Voorstellen`, `Advice`, `BesluitvormingAgenda` to `menu-layout.json#removals` so the nav entries are retired while their pages stay routable (ADR-044).
- [x] 2.3 Keep the `voorstel` / `adviesAanvraag` schemas + data and all former page routes intact (no schema or route deletions).

## 3. voorstel → decidesk migration

- [x] 3.1 Document that the voorstel → decidesk decision migration is already implemented by `dossiq-delegate-remaining-decisions-to-decidesk` (`AdviceDelegationService::raiseVoorstelBesluit` + `LinkInFlightRemainingDecisionsRepair`); no second migration mechanism is added.
- [x] 3.2 Record the decidesk `decisionType: format: uuid` 422 caveat and the production-data flag in the proposal.

## 4. Build, deploy, verify

- [x] 4.1 Build dossiq against published nc-vue beta.138 (USE_LOCAL_LIB=false); lint clean (0 errors); `appinfo/info.xml` at 0.2.20; chown js to 1000:1000; `occ upgrade` (no-op — DB already 0.2.20). Built `js/dossiq-main.js` serves with version cache-bust and contains `decidesk-decisions` + `besluitvorming-leaf-tab`.
- [x] 4.2 Verify (server-side — browser MCP unavailable this session): manifest `CaseDetail.config.sidebarTabs` carries `besluitvorming → BesluitvormingLeafTab` (order 35); `menu-layout.json#removals` contains `BesluitvormingGroup/Voorstellen/Advice/BesluitvormingAgenda` with their stale relocations dropped; all former page routes (`/voorstellen`, `/voorstellen/:id`, `/advice`, `/advice/:id`, `/besluitvorming/agenda`, `/besluitvorming/vergaderingen/:id`) remain registered (ADR-044). dossiq app page returns HTTP 200.
- [x] 4.3 decidesk write-path: fixed schema-96 `decisionType` `format:uuid` mis-import on this dev instance; decision `POST /api/objects/18/96` now returns 201 (was 422). See proposal caveat #2.
- [x] 4.4 UNBLOCKED and verified at runtime 2026-08-24. The wiring is on decidiq `development` and deployed here — `lib/AppInfo/Application.php` calls `Util::addInitScript(self::APP_ID, 'decidiq-integration-init')` and `js/decidiq-integration-init.js` is built. Verified in the browser ON A DOSSIQ PAGE, not by reading code: `OCA.OpenRegister.integrations.list()` returns 28 leaves including `decidesk-decisions` (label "Besluitvorming") with `tab`, `widget`, `widgetCompact`, `widgetExpanded` and `widgetEntity` surfaces. The fallback is no longer what a user sees.
      ⚠️ The PR reference above was wrong: `#100` in both `decidesk` and `decidiq` is "chore(spec): merge p2-minutes-and-decisions-other-t2", merged 2026-04-19 — a different change entirely. The wiring was confirmed BY CONTENT instead, which is the only check that would have caught this.
      🔑 The leaf id stays `decidesk-decisions` on the OLD app name. It is a cross-app runtime lookup, so it can only move when the registration on decidiq's side moves.
