# ADR-001: Information Architecture — topology rules

- **Status:** Accepted
- **Date:** 2026-05-23
- **Deciders:** Procest product + architecture
- **Scope:** procest (zaaksysteem). Cross-cutting only; per-spec placements
  live in the IA mapping table, not here.

## Context

Procest has ~77 specs spanning VTH, sociaal domein, bezwaar/beroep,
besluitvorming, inspecties, portalen (mijngemeente / leverancier / KCC /
DSO), rapportages and workflow/zaaktype configuration. Without a shared
topology contract every new spec drifts toward its own top-level menu
entry, its own settings page, or its own dashboard route. The result is
a sprawling sidebar, duplicate config surfaces, and per-zaaktype
navigation that breaks as soon as a tenant enables a second domain.

Hydra ADR-008 (annotation-driven UI) tells us *how* a spec is placed
(`@nav`, `@route`, `@widget`). It does not tell us *which* placements
are legal. This ADR fills that gap for procest: it is the topology
contract that the per-spec IA mapping rows in
`openspec/changes/*/proposal.md` must respect.

The full IA blueprint (9-item top-level navigation, per-menu sub-pages,
per-spec placement table, phasing) lives in the IA refactor change
under `openspec/changes/` and its archived equivalent. This ADR pulls
out only the cross-cutting *rules* — the parts that apply to every
future spec, not the one-time placements.

## Decision

We adopt the following nine topology rules. Every new procest spec and
every IA review MUST be checked against them.

### Rule 1 — Zaaktypes are data, never menu items

VTH / sociaal / bezwaar / subsidie / WOO / mandaat / leges definitions
are rows in one `Configuratie › Zaaktypes` register. There is **no**
top-level menu per zaaktype-family and no per-family settings page.

**Rationale:** zaaktypes are GEMMA-config-as-data; gemeenten add new
families at runtime via the visual editor. A menu-per-family makes the
sidebar grow unboundedly and forces a code change for every new tenant
domain.

**How to apply:** new zaaktype-family specs become `ACTION` rows
(seed-import buttons) or `SUB_PAGE` rows under
`Configuratie › Zaaktypes`, never `TOP_MENU`.

### Rule 2 — Workflow configuration collapses to one editor

All workflow-engine specs — engine, datamodel, status-transitions,
step-config, role-based-routing, automatic-actions,
methode-decompositie, import/export — live as `SETTING` or `SUB_PAGE`
rows under `Configuratie › Workflow-editor`. There is **no** separate
menu per engine aspect.

**Rationale:** the visual editor is the single touchpoint; engine
sub-systems are implementation detail that admins should not have to
navigate between.

**How to apply:** a workflow-related spec defaults to
`SETTING / Configuratie › Workflow-editor`; only the visual editor
itself gets `SUB_PAGE`.

### Rule 3 — Dashboard is a widget grid, not unique routes

Specs whose primary surface is a KPI tile, list snippet, map snippet
or heatmap become `WIDGET` placements on the role-default dashboard.
They do **not** get their own route or sidebar entry.

**Rationale:** rol-default-layouts (behandelaar, teamlead, parafant,
jurist, inspecteur, KCC-medewerker, manager) only work if widgets are
composable; per-spec routes break the grid.

**How to apply:** dashboard-shaped specs (case-dashboard-view,
milestone-tracking, map-component, future KPI specs) declare `WIDGET`
with one or more role-default placements.

### Rule 4 — Per-role default widget layouts are first-class config

The dashboard ships a default widget layout *per role*, not a single
shared default. Adding a widget MUST include its default role
membership(s); adding a role MUST include its default widget set.

**Rationale:** procest has seven primary roles with materially different
work; a one-size-fits-all dashboard forces every user to rebuild on
first login.

**How to apply:** every `WIDGET`-placed spec lists the roles it ships to
by default. A widget with no role-default is a bug, not a feature flag.

### Rule 5 — Portalen is one hub, not four top-levels

Mijngemeente (inwoner), Leverancier, KCC-werkplek and DSO/Omgevingsloket
all live under one `Portalen` top-level menu as `SUB_PAGE` rows. External
ingangen never get their own top-level entry.

**Rationale:** they share the "external party meets case" UX pattern
(verzoek aannemen → koppelen aan zaak → terug-koppelen). Splitting
them into four top-levels duplicates that pattern in four places and
makes the sidebar role-specific (KCC-medewerkers only see KCC, etc.).

**How to apply:** any future external-portal spec (e.g. ondernemers-,
ketenpartner-, intergemeentelijk portaal) becomes a `SUB_PAGE` under
`Portalen`, never `TOP_MENU`.

### Rule 6 — Termijnbewaking is a filter view, not a module

Termijn-overschreden / dwangsom-risico zaken live in
`Zaken › Termijnbewaking` as a filter on the shared case register. The
termijn-engine and dwangsom-trigger are `SETTING` rows on the same
register.

**Rationale:** all zaakdata lives in one register; a separate
"Termijnbewaking" module would either duplicate the register or split
the source of truth.

**How to apply:** time/deadline/dwangsom specs default to
`SUB_PAGE / Zaken › Termijnbewaking` (filter view) or
`SETTING / Configuratie`. Never `TOP_MENU`.

### Rule 7 — Inspecties stays top-level (the documented exception)

Inspections get their own top-level menu despite being case-derived,
because the mobile-offline PWA mode and the planning calendar are UX
patterns that do not fit the zaken-table view.

**Rationale:** the exception is real — PWA needs its own route stack
for offline service-worker scoping, and the calendar week/month
visualisation cannot share the facet-filtered list shell.

**How to apply:** this is the *only* documented top-level exception
for case-derived work. Future "this feels like it should be top-level"
requests must clear a higher bar (offline-only, calendar-shaped,
non-tabular).

### Rule 8 — Admin is a single drawer with sub-categories

System-level concerns (multi-tenant SaaS, observability, audit,
system-info, storage, branding) live under one
`Configuratie › Admin` drawer with sub-categories: tenant, branding,
koppelvlakken, observability, kwaliteit/CI-meta, storage. Each
sub-category is a section *inside* the drawer, not its own page.

**Rationale:** admin surface area grows fastest and is touched least;
sub-pages-per-concern produces a navigation jungle that admins use
twice a year.

**How to apply:** new admin/observability/SaaS specs declare
`SETTING / Configuratie › Admin › <sub-category>`, where sub-category
matches one of the documented six.

### Rule 9 — Detail-tabs are multi-affordance composition slots

Zaak-detail (10 tabs), bezwaar-detail (5 tabs), besluit-detail (7 tabs),
voorstel-detail and personeel-style detail surfaces use a tab strip
with conditional tabs (e.g. *Bezwaar/beroep* only when a bezwaar is
open on the zaak). Specs that add a domain-specific surface to an
existing case/decision/objection become `DETAIL_TAB` rows, not
sub-pages or modules.

**Rationale:** a per-aspect sub-page (Documenten-pagina, Locatie-pagina,
Leges-pagina) duplicates the zaak header, breadcrumbs and context-load;
tabs keep one URL per zaak and let conditional visibility hide tabs
that don't apply.

**How to apply:** specs that surface "more info on this same case"
default to `DETAIL_TAB / Zaken › <tab name>`. Conditional visibility
rules belong in the spec, not in the IA.

### Rule 10 — Tier-suffix collapsing: `-mvp`, `-full`, `-advanced` share a placement

When a capability ships across phases as `foo-mvp` (Phase 1) and
`foo-full` (Phase 2) or `foo-advanced` (Phase 3), all tier variants
share **one** IA placement. The mapping row says
"`foo-mvp` and `foo-full` → SUB_PAGE / Zaken › X" — not two rows.

**Rationale:** users do not navigate to "the MVP version" vs. "the full
version"; tiers are an internal phasing label. Surfacing them as
separate IA entries would mean either two menu items that disappear/
appear by phase, or a permanent split that admins must reconcile.

**How to apply:** when proposing a `-full` / `-advanced` follow-up spec,
re-use the existing IA row for its `-mvp` counterpart; the tier
upgrade is a change of behaviour at one placement, not a new placement.

## Consequences

### Positive

- Sidebar stays bounded at 9 top-level items (Dashboard, Mijn werk,
  Zaken, Inspecties, Bezwaar & beroep, Besluitvorming, Portalen,
  Rapportages, Configuratie) regardless of how many new zaaktypes,
  CAOs, portalen or workflow-aspects ship.
- IA mapping rows become routine: most new specs fall into "settings
  under Configuratie", "tab on a case", or "widget on dashboard"
  without debate.
- Role-default dashboards become a real product feature instead of an
  empty grid; multi-tenant admins get a predictable Admin drawer.
- Tier-2 / tier-3 follow-ups don't fork the navigation.

### Negative / trade-offs

- Inspecties as a top-level menu is a documented exception, which
  means every future "can mine be an exception too?" needs a Rule-7
  comparison. We accept that maintenance cost.
- Forcing per-role widget defaults raises the bar for any new
  dashboard widget spec; widgets without role defaults get rejected
  in review.
- The Configuratie tree gets deep (Zaaktypes / Workflow-editor /
  Rollen & rechten / Sjablonen / Integraties / Admin × six
  sub-categories). We accept depth in Configuratie in exchange for
  shallowness in the main nav.

### Review trigger

Re-open this ADR if any of the following happens:
- A new domain (e.g. procurement-suite, raadsinformatie-publicatie)
  is folded into procest rather than spun out, **and** doesn't fit any
  of the nine top-level menus.
- A second top-level exception (beyond Inspecties) is approved.
- Role-default dashboards are replaced by a shared dashboard.

## References

- IA blueprint + per-spec mapping: `openspec/changes/ia-refactor-procest/`
  (and its archived form once merged).
- Hydra ADR-008 — annotation-driven UI placement.
- Hydra ADR-022 — apps consume OR abstractions (zaaktypes-as-data
  enforcement layer).
- Sibling: `hrmq/openspec/architecture/adr-NNN-information-architecture.md`
  (parallel ADR; mirrors rules 1, 2, 4, 8, 10 with HRM specifics).
