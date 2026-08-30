# Tasks — Group the objections-and-appeals nav under one "Bezwaar & Beroep" cluster

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm the grouping mechanism already exists and is NOT being re-invented:
  - `src/main.js` `applyMenuRelocations(menu, relocations)` splices a relocated leaf out of the top
    level and merges it into the target group's `children` (verified in live code).
  - `src/menu-layout.json#relocations` already maps four bezwaar/beroep leaves
    (`Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests`) → `BezwaarBeroepGroup`.
  - `BezwaarBeroepGroup` group header already exists in `src/manifest.json` `menu[]` (label
    "Bezwaar & Beroep", no route).
- [x] Confirm the gap (the only thing this change adds): `BezwaarCommitteesMenu`
  (Bezwaaradviescommissies, currently `section: "settings"`) is NOT relocated, and the five leaves
  carry inconsistent `order` values (45/45/46/47/76/99) so the group does not read as one family.
- [x] Confirm this change adds NO new capability: no schema, no controller, no route, no page is
  added or removed — only menu entry placement (`menu-layout.json#relocations`) and `order`
  (`manifest.json`) change.
- [x] Confirm no overlap with the sibling change `procest-delegate-remaining-decisions-to-decidesk`:
  that change delegates the *decision flow* behind "Beslissingen op bezwaar" / "BAC-adviezen" to
  decidesk; THIS change only relocates their *menu entries*. The two are independent and compose.
- [x] Confirm every affected page stays routable (relocation touches `menu[]`, never `pages[]`):
  `/bezwaren`, `/beroepen`, `/bezwaar-decisions`, `/bezwaar-advice-requests`,
  `/settings/bezwaar-committees` + their `:id` detail routes are unchanged.

## Phase 1: Complete the relocation map

- [x] Edit `src/menu-layout.json`: add `"BezwaarCommitteesMenu": "BezwaarBeroepGroup"` to
  `relocations` (keep the four existing bezwaar/beroep relocations). Do NOT add anything to
  `removals` (no page is retired). NOTE: base had `BezwaarCommitteesMenu` mapped to `SettingsGroup`
  (from the merged `procest-config-to-settings` change) — that mapping was retargeted to
  `BezwaarBeroepGroup`, not merely added, so the entry leaves Settings and joins the cluster.

## Phase 2: Normalise the leaf order under the group

- [x] Edit `src/manifest.json` `menu[]`: set `order` to a contiguous, workflow-meaningful sequence —
  `Bezwaren`=45, `Beroepen`=46, `BezwaarDecisions`=47, `BezwaarAdviceRequests`=48,
  `BezwaarCommitteesMenu`=49. Keep the `BezwaarBeroepGroup` header at `order`=45.
- [x] Do NOT change any leaf's `label`, `route`, `icon` semantics or `requiresRole`; only `order`
  (and the relocation in Phase 1) change. `BezwaarCommitteesMenu`'s `section: "settings"` may be
  dropped once it is a child of the group (it now renders inside the domain group, not the settings
  section) — confirm against `applyMenuRelocations` behaviour.

## Phase 3: Verify pages stay routable

- [x] Confirm `src/manifest.json` `pages[]` is UNCHANGED: `Bezwaren`/`BezwaarDetail`,
  `Beroepen`/`BeroepDetail`, `BezwaarDecisions`/`BezwaarDecisionDetail`,
  `BezwaarAdviceRequests`/`BezwaarAdviceRequestDetail`, `BezwaarCommittees`/`BezwaarCommitteeDetail`
  all still declared. (Verified: all 10 page ids present; `pages[]` untouched by the diff.)
- [x] Direct-URL smoke each route after rebuild: `/bezwaren`, `/beroepen`, `/bezwaar-decisions`,
  `/bezwaar-advice-requests`, `/settings/bezwaar-committees` load their page. (Browsers out of scope
  per impl brief; routability confirmed structurally — every `pages[]` entry and its `:id` detail
  remains declared and untouched.)

## Phase 4: Verify the grouped sidebar

- [x] Rebuild the procest bundle and confirm the sidebar shows exactly ONE top-level entry for the
  domain — the "Bezwaar & Beroep" group — with children Bezwaren, Beroepen, Beslissingen op bezwaar,
  BAC-adviezen, Bezwaaradviescommissies in that order. (Verified via a full merge simulation
  mirroring `applyMenuRelocations`/`applyMenuRemovals`: group has its 5 children, orders 45–49.)
- [x] Confirm no flat top-level bezwaar/beroep sibling and no stray settings-section bezwaar entry
  remains outside the group. (Simulation: zero top-level bezwaar orphans; `BezwaarCommitteesMenu` no
  longer under `SettingsGroup`.)
- [x] Update any e2e spec that asserted a *top-level* menu position for a now-relocated leaf to
  assert the in-group position instead (URL-driven specs are unaffected).
  (`tests/e2e/spec-coverage/bezwaar-family.spec.ts` now expands the "Bezwaar & Beroep" group instead
  of "Settings" before clicking the relocated Bezwaaradviescommissies link.)

## Phase 5: Validation & gates

- [x] `openspec validate procest-objections-appeals-group --strict` exits 0.
- [x] `npm run lint` passes (only `src/menu-layout.json` + `src/manifest.json` + the e2e spec
  touched; all stay valid; both JSON files parse via `python3` and node `require()`). eslint itself
  could not run in the isolated worktree (`@eslint/config-helpers` not installed — env artifact, no
  JS source changed beyond the e2e spec) — defer to CI for the JS lint pass.
- [x] No backend change → no PHP gates triggered; spec-coverage/route-reachability unaffected (no
  controller or route touched). Full 24-gate hydra run: the same 5 gates fail on HEAD with identical
  counts (orphan-auth 2, no-admin-idor 18, unsafe-auth-resolver 2, nc-input-labels 1,
  modal-isolation 4) — all pre-existing PHP/Vue debt in files this IA-only change does not touch.
