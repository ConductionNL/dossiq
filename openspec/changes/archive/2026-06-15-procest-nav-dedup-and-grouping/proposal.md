# Proposal: procest-nav-dedup-and-grouping

kind: navigation-refactor (ADR-037 modular-config canonical layout · ADR-012 deduplication)

## Summary

procest's primary navigation has grown to **42 top-level menu entries** (the merged
`src/manifest.json#menu` array after `src/manifest.d/*` fragments merge). Three of those
entries are literal or near-literal **duplicates** that confuse users:

1. **"Cases" appears twice** — the group container `CasesGroup` (label `"Cases"`, no route,
   `order: 30`) and the leaf `Cases` (label `"Cases"`, `route: Cases`, `order: 40`). The leaf is
   already relocated under the group by `src/menu-layout.json#relocations`, so the rendered tree
   is a group **"Cases"** whose first child is also labelled **"Cases"** — two identical labels
   stacked.
2. **"Analytics" appears twice** — the group container `AnalyticsGroup` (label `"Analytics"`, no
   route, `order: 55`) and the leaf `Analytics` (label `"Analytics"`, `route: Doorlooptijd`,
   `order: 55`). Same pattern: group **"Analytics"** with a child also labelled **"Analytics"**.
3. **"Substitution" vs "Substitutions & reassignment"** — two separate top-level entries for the
   same concept: `SubstitutionMenu` (label `"Substitution"`, `route: SubstitutionSettings`,
   `/substitution`) and `SubstitutionAdminMenu` (label `"Substitutions & reassignment"`,
   `route: SubstitutionAdmin`, `/substitution-admin`). Two adjacent nav rows whose labels read as
   synonyms.

This change does two things, and **only** these two things:

- **(1) De-duplicate.** Make each duplicated label unique. The group keeps the canonical
  concept label; the leaf is relabelled to its specific surface (`Cases` → **"All cases"**,
  `Analytics` → **"Doorlooptijd"**). The duplicate **`SubstitutionMenu`** top-level nav entry is
  retired via `src/menu-layout.json#removals` — its **page stays routable** (`/substitution`,
  `SubstitutionSettingsView`) for deep links and e2e specs; the canonical `SubstitutionAdminMenu`
  entry remains the single nav home for substitution.
- **(2) Group the operational core.** Collapse the flat operational work surfaces into a small
  number of top-level groups using the **existing** `applyMenuRelocations` engine
  (`src/main.js`) and the **existing** `src/menu-layout.json` canonical-layout file (ADR-037 §
  "canonical nav layout"). A new **Work** group gathers `MyWork` / `Werkvoorraad` /
  `WorkflowBoard` / `Transfers`; the **Cases** group is completed with `Locations` /
  `StatusRecords` / `ArchiefDashboard`; the **Analytics** group is completed with `CaseMap` /
  `TermijnDashboard`. After this change the operational core renders as **3 groups** (Work,
  Cases, Analytics) instead of ~11 flat rows.

No page is deleted. No route changes. No component changes. Every removed/relocated entry's
**page stays routable** (ADR-037: "their PAGES stay routable for deep links and e2e specs").

## Depends on

**Depends on:** ADR-037 (modular config fragments + canonical `menu-layout.json` — `relocations`
and `removals` applied by `applyMenuRelocations` / `applyMenuRemovals` in `src/main.js`),
ADR-012 (deduplication — Phase 0 proves no capability is duplicated, only navigation entries).

## Deduplication rationale (ADR-012)

This change **removes** duplicate *navigation*, it does not remove or duplicate any *capability*.

- The retired `SubstitutionMenu` entry and the kept `SubstitutionAdminMenu` entry route to
  **different** pages (`SubstitutionSettings` = the per-user "set my own substitute" settings view;
  `SubstitutionAdmin` = the team-wide reassignment admin view). The page-level capabilities are
  distinct and **both survive** — only the redundant *second top-level nav row* is removed. The
  per-user substitution settings remain reachable at `/substitution` and are the natural target of
  the sibling **procest-config-to-settings** change (which will relocate it under Settings); this
  change does **not** pre-empt that move, it only stops the duplicate top-level row.
- The relabelled `Cases` and `Analytics` leaves keep their exact routes (`Cases`, `Doorlooptijd`)
  and pages; only their `label` strings change so a group and its child no longer read identically.
- This change does **NOT** touch the Objections/Appeals cluster (`Bezwaren` / `Beroepen` /
  `BezwaarDecisions` / `BezwaarAdviceRequests` / `BezwaarCommitteesMenu` → sibling
  **procest-objections-appeals-group**), the config→Settings relocations of the remaining admin
  leaves (`CaseTypesMenu`, `Legesverordeningen…`, `Parafeerroutes`, `WorkflowDefinitions`,
  `AutomaticActions`, `WmsLayers`, `Partners`, `Tenants`, `LhsMatrices`, `LhsRecommendations`,
  `TenantOnboarding`, `Features & roadmap` → sibling **procest-config-to-settings**), nor any
  decision delegation (sibling **procest-delegate-remaining-decisions-to-decidesk**). Those entries
  are intentionally left flat by this change to avoid overlap; this change scopes strictly to the
  duplicate dedup + the operational-core grouping (Work / Cases / Analytics).
