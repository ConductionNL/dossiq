# Design — procest-nav-dedup-and-grouping

## Context

procest builds its navigation from `src/manifest.json#menu` merged with every
`src/manifest.d/*.json` fragment (ADR-037), then applies `src/menu-layout.json` as the
**canonical layout pass** in `src/main.js`:

```js
merged.menu = applyMenuRelocations(merged.menu, menuLayout.relocations)
merged.menu = applyMenuRemovals(merged.menu, menuLayout.removals)
```

- `relocations`: `{ sourceLeafId: targetGroupId }` — moves a leaf under a group container
  (`applyMenuRelocations`, lines 106-145). A target group is a menu entry with **no `route`** and a
  `children` array; the engine's final filter drops route-less nodes that have no children, so an
  empty group never renders.
- `removals`: `[leafId, …]` — drops a **leaf** menu entry after relocation; the corresponding
  **page is untouched and stays routable** (`applyMenuRemovals`, lines 155-165).

The grouping mechanism therefore **already exists** and `menu-layout.json` already relocates the
operational leaves into `CasesGroup` / `AnalyticsGroup` / `BesluitvormingGroup` etc. The remaining
problems are (a) the group label collides with a relocated child label, and (b) some operational
leaves are not yet grouped, and (c) the duplicate substitution row is still present.

## Key decisions

1. **Fix duplicates by relabelling the leaf, not deleting it.** The leaf `Cases`/`Analytics` pages
   are real, distinct surfaces (the "all cases" index; the doorlooptijd dashboard). Deleting them
   would lose a destination. Instead we make the labels unique so a group and its child never read
   identically: group keeps the concept word, leaf gets its specific surface name. This is a pure
   `label` edit in `src/manifest.json#menu`; routes/pages/components untouched.

2. **Retire the duplicate substitution row via `removals`, keep the page.** `SubstitutionMenu` is
   the per-user "my substitute" settings view; `SubstitutionAdminMenu` is the team reassignment
   admin view. They are different pages but their adjacent top-level labels read as synonyms. We
   add `"SubstitutionMenu"` to `menu-layout.json#removals` (currently `[]`). `SubstitutionAdmin`
   stays as the single substitution nav home. The `/substitution` page remains routable; the
   sibling **procest-config-to-settings** change later relocates it under Settings.

3. **Introduce a Work group; complete the Cases and Analytics groups — via `menu-layout.json`
   only.** A new `WorkGroup` container entry (label "Work", no route) is added to
   `src/manifest.json#menu`; the leaves `MyWork`, `Werkvoorraad`, `WorkflowBoard`, `Transfers` are
   relocated into it via `menu-layout.json#relocations`. (`Werkvoorraad`/`WorkflowBoard`/`Transfers`
   currently relocate to `CasesGroup`; they move to `WorkGroup` because they are *work-queue*
   surfaces, leaving `CasesGroup` for the case dossier surfaces.) `Locations`, `StatusRecords`,
   `ArchiefDashboard` join `CasesGroup`; `CaseMap`, `TermijnDashboard` join `AnalyticsGroup`
   (the latter two are already relocated for Analytics — only `CaseMap` is reconfirmed and
   `Locations`/`StatusRecords`/`ArchiefDashboard`/`Werkvoorraad`/`WorkflowBoard`/`Transfers` are
   re-pointed). No `applyMenuRelocations` code change is needed — it already does multi-pass group
   resolution.

4. **No fragment surgery.** All edits are confined to `src/manifest.json` (relabel two leaves; add
   one `WorkGroup` container) and `src/menu-layout.json` (re-point relocations; add one removal).
   The `manifest.d/*` fragments that *declare* the leaves are not edited (ADR-037: fragments own
   *what exists*, `menu-layout.json` owns *where it lives*).

## Alternatives considered

- **Delete the duplicate leaf entries entirely.** Rejected — the `Cases`/`Analytics` leaves are
  the actual landing pages of their group concept; we want one click from the group to land on the
  index/dashboard. Relabelling preserves the destination while killing the visual duplicate.
- **Collapse `SubstitutionMenu` and `SubstitutionAdmin` into one page.** Rejected — out of scope
  (page-merge is a behaviour change). This change only de-duplicates the nav row; the
  page-relocation/merge is the sibling **procest-config-to-settings**'s concern.
- **Hard-code groups in a fragment.** Rejected — violates ADR-037's separation (fragments =
  *what*, `menu-layout.json` = *where*). The canonical-layout file is the single decision point.

## Exact menu entries / pages touched

### Relabelled leaves (`src/manifest.json#menu`) — route & page unchanged

| Entry id   | Old label   | New label      | Route (unchanged) | Page (unchanged, routable) |
|------------|-------------|----------------|-------------------|----------------------------|
| `Cases`    | "Cases"     | "All cases"    | `Cases`           | `/cases` (index, schema `case`) |
| `Analytics`| "Analytics" | "Doorlooptijd" | `Doorlooptijd`    | `/doorlooptijd` (dashboard)     |

### New group container added (`src/manifest.json#menu`)

| Entry id   | Label  | Route | Order | Notes |
|------------|--------|-------|-------|-------|
| `WorkGroup`| "Work" | none  | 20    | Container for the work-queue leaves; renders only because it has children |

### Relocations — `src/menu-layout.json#relocations` (sourceLeafId → targetGroupId)

| Leaf id        | Old target    | New target    |
|----------------|---------------|---------------|
| `MyWork`       | (none / flat) | `WorkGroup`   |
| `Werkvoorraad` | `CasesGroup`  | `WorkGroup`   |
| `WorkflowBoard`| `CasesGroup`  | `WorkGroup`   |
| `Transfers`    | `CasesGroup`  | `WorkGroup`   |
| `Cases`        | `CasesGroup`  | `CasesGroup` (unchanged) |
| `Locations`    | (none / flat) | `CasesGroup`  |
| `StatusRecords`| (none / flat) | `CasesGroup`  |
| `ArchiefDashboard` | (none / flat) | `CasesGroup` |
| `Analytics`    | `AnalyticsGroup` | `AnalyticsGroup` (unchanged) |
| `CaseMap`      | `AnalyticsGroup` | `AnalyticsGroup` (unchanged) |
| `TermijnDashboard` | `AnalyticsGroup` | `AnalyticsGroup` (unchanged) |

(Leaf menu ids: `Locations` = `LocationsMenu`, `StatusRecords` = `StatusRecordsMenu`,
`ArchiefDashboard` = `ArchiefDashboardMenu` — relocations key on the **menu entry id**, which for
these admin leaves is the `…Menu`-suffixed id in `src/manifest.json#menu`.)

### Removals — `src/menu-layout.json#removals`

| Leaf id          | Reason | Page stays routable |
|------------------|--------|---------------------|
| `SubstitutionMenu` | Duplicate substitution row (synonym of `SubstitutionAdminMenu`) | yes — `/substitution`, `SubstitutionSettingsView` |

## Migration / rollout

Pure front-end config (`src/manifest.json` + `src/menu-layout.json`). No backend, no schema, no
data migration, no repair step. Rollout = rebuild the bundle. Rollback = revert the two files.

Because every change is label/layout-only and every page stays routable, existing deep links,
bookmarks, and e2e specs that navigate by **route** are unaffected. Specs that assert on the old
duplicate **labels** must be updated to the new unique labels (tracked in tasks).

## Risks

- **Relocation-target ordering.** `WorkGroup` (order 20) must sort before `CasesGroup` (order 30).
  The `WorkGroup` container is added with `order: 20` to keep Work above Cases. Low risk —
  ordering is data-only.
- **A relocated leaf id must match its `menu` entry id.** `Locations`/`StatusRecords`/
  `ArchiefDashboard` are declared with `…Menu`-suffixed ids in `src/manifest.json#menu`; the
  relocation keys must use the exact menu ids. Verified against the merged menu array in Phase 0.
- **e2e label assertions.** Tests asserting the literal labels "Cases"/"Analytics" twice, or the
  "Substitution" top-level row, will need the new labels. Enumerated in tasks; no route changes so
  route-based navigation in specs is safe.
