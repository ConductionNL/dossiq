---
kind: code
depends_on: []
---

# Proposal: widget-roles-declared

The dossiq consumer half of **launchpad
`dashboards-and-who-may-see-them`** (ConductionNL/launchpad#637), round 4
discovery cluster 12 "Dashboards, widgets and who may see them"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14), and gap register ledger row
10.1. Eleven candidates, one `must`, owner launchpad, size M. dossiq's
half is one thing: every dossiq widget declares which roles may see it.

The register records why the cluster carried nothing
(`procest/_gaps/gap-register.json`, `discovery.counts.uncarried_reason`,
cluster 12): "launchpad opened no parity umbrella, so the cluster it owns
carries nothing". launchpad#637 has opened it, and this is dossiq's side.

## Why

A dossiq dashboard shows counts, overdue lists and workload tiles. Some of
them are a teamleider's view of their team, some are a case handler's own
work, and one is a financial figure a case handler has no business
reading.

Today a widget is placed on a page and everyone who reaches the page sees
it. So the only way to keep a figure from the wrong reader is to keep the
page from them, which takes the rest of the page with it.

## The candidate dossiq consumes

| candidate | relevance | dossiq | what dossiq's half is |
|---|---|---|---|
| C-reporting-22 | must | partial | every dossiq widget declares the roles that may see it, and the plane enforces it |

Evidence verbatim from the lane
(`_round4/discovery/candidates.json`, C-reporting-22): "valtimo: Dashboard
(Dashboard.md)". Ledger row 10.1 carries the same claim.

The other ten members are launchpad's and dossiq builds no half of them:
the canned report, the dated status report, the personal arrangement over
a shared dashboard, one person's activity, an embedded external page, the
intensity calendar, searching inside a widget's result, the geographic
trend, the colleagues' activity stream and the storage figure.

**D6 was answered relevance-led**, so the `must` enters on one driven
passer. **D17** does not reach this cluster.

## What changes

- Every widget dossiq declares in `src/manifest.json` carries the roles
  that may see it.
- A reader who holds none of a widget's roles does not receive its data,
  and the widget is not rendered as an empty box either: the page is laid
  out without it.
- The enforcement is on the read, not in the browser. A widget a reader
  may not see answers nothing, whatever the page asks for.
- A widget declared with a role that does not resolve refuses to render
  and is reported, rather than defaulting to visible.
- A structural test fails when a dossiq widget declares no roles and
  carries no reason-bearing allowlist entry, so the next widget cannot be
  added without the question being asked.

## Ownership

launchpad owns the dashboard, the composition, the personal arrangement
and the plane that enforces the scoping. dossiq owns its own widget
declarations and the case-side data behind them.

| half | app | artefact |
|---|---|---|
| the dashboard, its composition and who may see it | launchpad | `dashboards-and-who-may-see-them`, launchpad#637 |
| the roles a declaration names, and where they came from | openregister | `permission-provenance-and-deny` and `rbac-department-role-matrix`; dossiq's consumer half is `case-grants-name-their-source`, wave 1 |
| the tiles a person arranges on their own dashboard | nextcloud-vue | `dashboard-layout-per-user`, the register's artefact for row 10.10 |

Every half has an artefact. This change opens no request for a new change
in another repo.

## ADRs

- Company ADR-102: config absence fails closed with a status. A widget
  whose declared role does not resolve is not rendered.
- Company ADR-004: the Nextcloud-idiomatic pattern, and its hard rule that
  a frontend route is not an access check. A widget hidden only in the
  browser is the same defect one layer down.
- Company ADR-060: a test that cannot fail is phantom green. The
  structural test is what stops the declaration from being optional in
  practice.

## Capabilities

- Modified: `dashboard`: every widget declares the roles that may see it,
  enforced on the read.

## Impact

`src/manifest.json` (every widget declaration),
`src/views/widgets/`, the widget data endpoints, Dutch and English
strings.

## Out of scope

- The dashboard itself and its composition. launchpad#637.
- A person's own arrangement of tiles. nextcloud-vue, row 10.10.
- Field-level visibility inside a record. dossiq `sensitive-fields-declared`
  and `field-rules-declared`, both open.
