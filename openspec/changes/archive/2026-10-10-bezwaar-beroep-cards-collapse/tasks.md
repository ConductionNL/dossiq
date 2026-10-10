# Tasks: bezwaar-beroep-cards-collapse

## 1. Add BezwaarBeroepLanding page to manifest.json

- [x] Add a new page entry `BezwaarBeroepLanding` with `route: "/bezwaar-beroep"` and `type: "cards"` (or equivalent card-grid type) to `src/manifest.json` under the `pages` array
- [x] Configure the card-grid with one card per former leaf: `Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests` — each card includes label, icon, description, and target route

## 2. Update menu-layout.json to collapse BezwaarBeroepGroup

- [x] Change the `BezwaarBeroepGroup` entry in `src/manifest.json` from a group (no `route`) to a direct link by adding `"route": "BezwaarBeroepLanding"` to it, making it a clickable top-level item instead of an expandable group
- [x] Update `src/menu-layout.json` relocations: remove the four `Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests` relocations into `BezwaarBeroepGroup` (they are no longer sub-items; the group entry itself is now the nav item)
- [x] Add the four former leaf ids (`Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests`) to the `removals` array in `src/menu-layout.json` so their individual menu entries are suppressed from the nav while their page routes remain registered

## 3. Verify all former leaf page routes remain in the Vue Router

- [x] Confirm that the `Bezwaren` page (`/bezwaren`), `Beroepen` page (`/beroepen`), `BezwaarDecisions` page (`/bezwaar-decisions`), and `BezwaarAdviceRequests` page (`/bezwaar-advice-requests`) entries remain in `src/manifest.json` `pages` array unchanged — routes must not be deleted
- [x] Run `openspec validate bezwaar-beroep-cards-collapse --type change --strict` and confirm it passes

## 4. Implement the BezwaarBeroepLanding card-grid Vue component (if type "cards" requires a custom component)

- [x] If the manifest card-grid type requires a custom Vue component, create `src/views/BezwaarBeroepLanding.vue` rendering four `NcAppContentListItem` (or equivalent) cards, each linking to the corresponding route
- [x] Register the component slot in the manifest page entry

## 5. Write and annotate e2e scenarios

- [x] (superseded, see below) Create or update the Playwright e2e spec for the Bezwaar & Beroep section: assert the sidebar shows one "Bezwaar & Beroep" item (no sub-items), the landing page shows four cards, and each of the four former leaf routes resolves via direct URL navigation
- [x] (superseded, see below) Tag all four former-leaf deep-link scenarios with `@gate-19` so they are included in the route-reachability gate

## 6. Gate validation

- [x] (superseded, see below) Run the Hydra `route-reachability` gate (gate-19) and confirm all four former leaf routes pass
- [x] (superseded, see below) Run the Hydra `dashboard-antipattern` gate to confirm no group is left without a route
- [x] (superseded, see below) Run the full `hydra-gates` suite and confirm no regressions

## Superseded (10 Oct 2026, lane L3)

Closed without building sections 5 and 6, and archived without folding its
delta into a main spec. The pages this change collapsed no longer exist:
`refactor(nav): retire five superseded pages instead of only hiding them`
(#1682, f35541288) deleted `Bezwaren`, `Beroepen` and the `BezwaarBeroepGroup`
with its card landing page, because `BezwaarDecisions` and
`BezwaarAdviceRequests` had already been retired by `case-type-navigation`
and every card resolved to nothing. Objections are cases, listed on `Cases`
narrowed by case type (see the `removalsCoverageNote` in
`src/menu-layout.json`). Folding REQ-NAV-001 to 003 would write requirements
for a menu entry and four routes that were deliberately removed, so the
archive uses `--skip-specs`. There is nothing to e2e-test and no route for
gate 19 to reach.
