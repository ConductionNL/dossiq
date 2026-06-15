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

- [ ] Edit `src/menu-layout.json`: add `"BezwaarCommitteesMenu": "BezwaarBeroepGroup"` to
  `relocations` (keep the four existing bezwaar/beroep relocations). Do NOT add anything to
  `removals` (no page is retired).

## Phase 2: Normalise the leaf order under the group

- [ ] Edit `src/manifest.json` `menu[]`: set `order` to a contiguous, workflow-meaningful sequence —
  `Bezwaren`=45, `Beroepen`=46, `BezwaarDecisions`=47, `BezwaarAdviceRequests`=48,
  `BezwaarCommitteesMenu`=49. Keep the `BezwaarBeroepGroup` header at `order`=45.
- [ ] Do NOT change any leaf's `label`, `route`, `icon` semantics or `requiresRole`; only `order`
  (and the relocation in Phase 1) change. `BezwaarCommitteesMenu`'s `section: "settings"` may be
  dropped once it is a child of the group (it now renders inside the domain group, not the settings
  section) — confirm against `applyMenuRelocations` behaviour.

## Phase 3: Verify pages stay routable

- [ ] Confirm `src/manifest.json` `pages[]` is UNCHANGED: `Bezwaren`/`BezwaarDetail`,
  `Beroepen`/`BeroepDetail`, `BezwaarDecisions`/`BezwaarDecisionDetail`,
  `BezwaarAdviceRequests`/`BezwaarAdviceRequestDetail`, `BezwaarCommittees`/`BezwaarCommitteeDetail`
  all still declared.
- [ ] Direct-URL smoke each route after rebuild: `/bezwaren`, `/beroepen`, `/bezwaar-decisions`,
  `/bezwaar-advice-requests`, `/settings/bezwaar-committees` load their page.

## Phase 4: Verify the grouped sidebar

- [ ] Rebuild the procest bundle and confirm the sidebar shows exactly ONE top-level entry for the
  domain — the "Bezwaar & Beroep" group — with children Bezwaren, Beroepen, Beslissingen op bezwaar,
  BAC-adviezen, Bezwaaradviescommissies in that order.
- [ ] Confirm no flat top-level bezwaar/beroep sibling and no stray settings-section bezwaar entry
  remains outside the group.
- [ ] Update any e2e spec that asserted a *top-level* menu position for a now-relocated leaf to
  assert the in-group position instead (URL-driven specs are unaffected).

## Phase 5: Validation & gates

- [ ] `openspec validate procest-objections-appeals-group --strict` exits 0.
- [ ] `npm run lint` passes (only `src/menu-layout.json` + `src/manifest.json` touched; both stay
  valid JSON).
- [ ] No backend change → no PHP gates triggered; spec-coverage/route-reachability unaffected (no
  controller or route touched).
