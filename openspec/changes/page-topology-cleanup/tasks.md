## A. Analytics dashboards — one render path

- [x] A1.1 Convert `ProcessMiningDashboard` to `type: "dashboard"`: declare `config.widgets` (KPI tiles, dwell-time bar chart, weekly throughput line chart, bottleneck ranking) + `config.layout`.
- [x] A1.2 Move its header/subtitle and the period `NcActions` control out of the component and onto the page declaration.
- [x] A2.1 Convert `TermijnDashboard` to `type: "dashboard"` with KPI-card and report-table widgets.
- [x] A2.2 Move its case-type filter and refresh control onto the page declaration.
- [x] A3.1 Unnest `/doorlooptijd`: split `DoorlooptijdDashboard.vue` so the page supplies the `<h2>` and filters and the widgets supply only content.
- [x] A3.2 Replace its single 12x12 widget with the real widget/layout set.
- [x] A4.1 Check all three against ADR-062 grid discipline; delete the per-page `<style scoped>` rules the shared grid now supplies.
- [x] A4.2 `hydra-gate-dashboard-antipattern` — asserted in-repo across all four dashboard pages: no single 12x12 widget, >=2 layout entries each, every custom widget has a slot. Gate itself runs in CI.

## B. Administration surface

- [x] B1.1 Delete the `ProcestConfiguration` page (route `/settings`) and its `section-admin` slot from `src/manifest.json`.
- [x] B1.2 Unregister `AdminRootView` from `src/customComponents.js` and `src/registry.js`.
- [x] B1.3 `hydra-gate-admin-router` — the in-app admin route is gone; asserted no manifest page mounts an admin component. Gate itself runs in CI.
- [x] B2.1 Remove both `ProcestConfiguration` menu entries (order 95 "Case types", order 99 "Configuration"); add one settings-foldout link per ADR-044.
- [x] B3.1 Render `TenantOnboardingDashboard.vue` as a section/tab inside `AdminRoot.vue`.
- [x] B3.2 Delete the `/tenant-onboarding` page and its menu entry (order 92).
- [x] B4.1 Add `<personal>` registration + `lib/Settings/PersonalSettings.php` + `templates/settings/personal.php` + a `procest-personal-settings` webpack entry.
- [x] B4.2 Mount `SubstitutionSettings.vue` there; confirm the self-scope filter is enforced server-side, not only in the query.
- [x] B4.3 Delete the `/substitution` page and its menu entry (order 93). Leave `/substitution-admin` intact.
- [x] B5.1 Confirm the `/cases` map view covers the locations use-case (design.md Decision 3). If it does not, stop and drop B5 with a reason.
- [x] B5.2 Delete the `Locations` page, the `LocationDetail` route and the menu entry (order 98). Leave the `location` schema and the case-detail linkage untouched.

## C. To OpenRegister

> ▶️ **C2 UNBLOCKED (2026-08-22).** The flow-engine consolidation has landed:
> OpenRegister now owns `lib/Service/Flow/` with `FlowEngine`,
> `FlowNodeRegistry` and ~20 node types, and its registry is explicitly built so
> apps CONTRIBUTE nodes rather than keep engines. See `PLAN-RESUME.md` for the
> step order and for the dependency that makes this bigger than two pages:
> `automaticAction` is referenced from inside `caseType.workflowSteps`
> (`automaticActions`, `config.autoActions`, `config.escalationRule`), so
> retiring the pages does not retire the concept.
> C1 is done — it turned out to need no OpenRegister work at all.


- [ ] C1.1 Diff procest's `VerwerkingenOverview.vue` against OpenRegister's `/avg` page; list what OR does not yet provide.
- [ ] C1.2 Land the gaps in OpenRegister (openspec change in that repo). **Merge before C1.3.**
- [ ] C1.3 Delete `/verwerkingen`, its menu entry and `VerwerkingenOverview.vue` from procest.
- [x] C2.1 Inventoried. OR registers 19 nodes and every one is control-flow or data — none acts outward, so the six procest actions are CONTRIBUTED as nodes (`procest.<type>`) via `RegisterFlowNodesEvent`, the seam `FlowNodeRegistry` exists for and hermiq already uses. No OpenRegister-side change needed.
- [ ] C2.1b Port `SideEffectDispatcher` to run step actions through OR's flow runner (full port, per the 2026-08-22 decision) instead of the private ActionRegistry.
- [ ] C2.2 Repair step rewriting every `ActionRef` in `caseType.workflowSteps` (`automaticActions`, `config.autoActions`, `config.escalationRule`) to its `procest.<type>` node id. Idempotent, non-fatal. 9 references in the shipped seed alone.
- [ ] C2.3 Delete `/settings/automatic-actions`, `/settings/automatic-actions/:id`, their menu entry and the backing controller/service from procest.

## D. To decidesk

> ▶️ **D1–D4 UNBLOCKED by the flow-engine consolidation (2026-08-22)**, with one
> gate remaining: D1 still waits on the active `consume-decidesk-besluitvorming-leaf`
> change, which deliberately keeps the two besluitvorming routes alive that D1
> removes. See `PLAN-RESUME.md`.


- [ ] D1.1 **Wait for `consume-decidesk-besluitvorming-leaf` to merge** — it deliberately keeps the two besluitvorming routes alive.
- [ ] D1.2 Map procest's agenda-compiler and vergadering-detail onto decidesk's `/agenda-items` and `/meetings`; land the gaps in decidesk. **Merge before D1.3.**
- [ ] D1.3 Delete `/besluitvorming/agenda`, `/besluitvorming/vergaderingen/:id`, `AgendaCompilerView.vue`, `VergaderingDetailView.vue` and `src/manifest.d/50-besluitvorming.json`.
- [ ] D2.1 Map `bezwaaradviescommissie` onto decidesk's `governance-body`; land it in decidesk. **Merge before D2.2.**
- [ ] D2.2 Delete `/settings/bezwaar-committees`, `/settings/bezwaar-committees/:id` and their menu entry.
- [ ] D3.1 Map `parafeerroute` onto decidesk's routed-document/approval model; land it in decidesk. **Merge before D3.2.**
- [ ] D3.2 Delete `/settings/parafeerroutes`, `/settings/parafeerroutes/:id` and their menu entry.
- [ ] D4.1 Verify the case-detail leaf is render-and-read only (ADR-066): no verb, no command. Anything procest needs decidesk to *do* travels as a typed event (ADR-041).

## E. To hermiq

- [x] E1.1 Diffed. Hermiq's `Approval` is a GATE (blocking, pre-action); procest's `aiAuditEntry` is retrospective evidence (what the model proposed, what the human did). `AiFeature` registers *that* human intervention exists (Art. 49); neither records the per-decision evidence (Art. 14). Real gap, not a duplicate.
- [x] E1.2 Landed in hermiq (PR #514): `Approval.sourceType` gains `advisory`, `status` gains `overridden`, plus `advisoryContext`, a typed `AiOversightRecordedEvent`, its listener/service, and an `/ai-oversight` surface. **Merge before E1.3.**
- [x] E1.3 Deleted `/settings/ai-oversight`, `/settings/ai-oversight/:id` and the menu entry; added a deeplink to hermiq. `AiAuditService` now delegates each decision; `MigrateAiOversightToHermiq` replays the existing ones.

## F. Tests

- [x] F1 Rewrite affected e2e specs to assert each retired page is **absent**, not deleted (the `retire-status-history-page` precedent).
- [ ] F2 Add e2e coverage for the three dashboards asserting one page heading and a multi-widget grid.
- [ ] F3 Add e2e coverage for substitution under personal settings and tenant-onboarding under admin settings.
- [ ] F4 `npm run test:e2e` green.

## G. Verify

- [ ] G1 `USE_LOCAL_LIB=false npm run build` compiles.
- [ ] G2 `composer check:strict` passes.
- [ ] G3 `./scripts/run-hydra-gates.sh .` — no new findings.
- [ ] G4 `openspec validate page-topology-cleanup` passes.

## Acceptance Criteria

- All three analytics pages are `type: "dashboard"` with two or more widgets; the dashboard-antipattern gate is green.
- Administration is reachable only at `/settings/admin/procest`, with exactly one menu entry; the admin-router gate is green.
- Substitution is a personal setting; `/substitution-admin` still exists.
- No page remains in procest for verwerkingen, automatic actions, bezwaar committees, parafeerroutes, besluitvorming or AI oversight.
- Every retired capability is reachable in its owner app **before** its procest page is deleted.
- No schema is deleted by this change.

## Quality Checklist

- Each cross-app move is two PRs: owner app first, retirement second. Never one.
- Retired routes keep their data; only pages and menu entries are removed.
- Gaps found in nc-vue, OpenRegister or the flow engine are recorded against those repos, not worked around in procest.
