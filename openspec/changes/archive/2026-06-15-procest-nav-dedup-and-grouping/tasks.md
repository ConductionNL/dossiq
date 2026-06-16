# Tasks — procest navigation dedup & operational-core grouping

## Phase 0: Deduplication Check (ADR-012)

- [x] Enumerate the merged top-level menu (`src/manifest.json#menu` + `src/manifest.d/*`): **42**
      entries.
- [x] Identify literal/near-literal duplicate menu entries and their declaring source:
  - [x] **"Cases" ×2** — `CasesGroup` (label "Cases", no route, order 30) **and** `Cases`
        (label "Cases", route `Cases`, order 40). Both declared in `src/manifest.json#menu`.
        `Cases` is relocated under `CasesGroup` by `src/menu-layout.json#relocations`.
  - [x] **"Analytics" ×2** — `AnalyticsGroup` (label "Analytics", no route, order 55) **and**
        `Analytics` (label "Analytics", route `Doorlooptijd`, order 55). Both declared in
        `src/manifest.json#menu`. `Analytics` is relocated under `AnalyticsGroup`.
  - [x] **Substitution ×2** — `SubstitutionMenu` (label "Substitution", route
        `SubstitutionSettings`, `/substitution`) **and** `SubstitutionAdminMenu`
        (label "Substitutions & reassignment", route `SubstitutionAdmin`, `/substitution-admin`).
        Both declared in `src/manifest.json#menu`. Distinct pages, synonym labels.
- [x] Confirm this change removes **navigation** duplicates only — no capability/page is deleted or
      duplicated; the grouping engine (`applyMenuRelocations`/`applyMenuRemovals`, `src/main.js`)
      already exists and is reused (not re-implemented).
- [x] Confirm boundaries with sibling changes (no overlap): Objections/Appeals cluster →
      **procest-objections-appeals-group**; config→Settings relocations →
      **procest-config-to-settings**; decision delegation →
      **procest-delegate-remaining-decisions-to-decidesk**. This change touches none of those entries.

## Phase 1: De-duplicate labels (`src/manifest.json#menu`)

- [ ] Relabel leaf `Cases`: `"label": "Cases"` → `"label": "All cases"` (route `Cases` unchanged,
      page `/cases` unchanged & routable).
- [ ] Relabel leaf `Analytics`: `"label": "Analytics"` → `"label": "Doorlooptijd"` (route
      `Doorlooptijd` unchanged, page `/doorlooptijd` unchanged & routable).
- [ ] Verify `CasesGroup` keeps label "Cases" and `AnalyticsGroup` keeps label "Analytics" (group
      = canonical concept word; no further edit needed).

## Phase 2: Retire the duplicate substitution nav row (`src/menu-layout.json`)

- [ ] Add `"SubstitutionMenu"` to `src/menu-layout.json#removals` (currently `[]`).
- [ ] Confirm the `SubstitutionSettings` page (`/substitution`, `SubstitutionSettingsView`) is NOT
      removed from `src/manifest.json#pages` — it stays routable for deep links / e2e.
- [ ] Confirm `SubstitutionAdminMenu` remains the single substitution nav entry.

## Phase 3: Group the operational core (`src/manifest.json#menu` + `src/menu-layout.json`)

- [ ] Add a `WorkGroup` container entry to `src/manifest.json#menu`:
      `{ "id": "WorkGroup", "label": "Work", "icon": "icon-toggle", "order": 20 }` (no `route`).
- [ ] In `src/menu-layout.json#relocations`, point the work-queue leaves at `WorkGroup`:
      `MyWork → WorkGroup`, `Werkvoorraad → WorkGroup`, `WorkflowBoard → WorkGroup`,
      `Transfers → WorkGroup` (move `Werkvoorraad`/`WorkflowBoard`/`Transfers` off `CasesGroup`).
- [ ] In `src/menu-layout.json#relocations`, complete `CasesGroup`:
      `LocationsMenu → CasesGroup`, `StatusRecordsMenu → CasesGroup`,
      `ArchiefDashboardMenu → CasesGroup` (keep `Cases → CasesGroup`).
- [ ] In `src/menu-layout.json#relocations`, confirm `AnalyticsGroup` membership:
      `Analytics → AnalyticsGroup`, `CaseMap → AnalyticsGroup`,
      `TermijnDashboardMenu → AnalyticsGroup` (already present — verify only).

## Phase 4: Verify & sync tests

- [ ] Rebuild bundle; confirm the rendered menu shows **Work / Cases / Analytics** groups, no
      duplicate "Cases" or "Analytics" labels, and a single substitution entry.
- [ ] Update any e2e/unit spec that asserts the old duplicate labels ("Cases" twice, "Analytics"
      twice) or the "Substitution" top-level row to the new labels / grouped structure. Route-based
      navigation in specs needs no change (all routes preserved).
- [ ] `cd procest && openspec validate procest-nav-dedup-and-grouping --strict` passes.
