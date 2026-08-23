# Design — page-topology-cleanup

## The shape of the problem

Twelve pages, but only three underlying mistakes. Naming them separately is what
keeps the change tractable, because the fix for each is different.

| Mistake | Pages | Fix shape |
|---|---|---|
| Wrong manifest `type` | 3 analytics dashboards | rewrite the page declaration; the data providers are already correct |
| Capability in the wrong app | verwerkingen, automatic-actions, bezwaar-committees, parafeerroutes, besluitvorming, ai-oversight | land in the owner app first, retire here second |
| Admin surface duplicated or misplaced | in-app `/settings`, tenant-onboarding, substitution, locations | move into the settings framework, or delete |

## Decision 1 — process-mining becomes the reference, but not by copying its CSS

The instinct is "make the others look like process-mining". That would be wrong:
process-mining looks better because it composes nc-vue leafs (`CnKpiGrid`,
`CnStatsBlock`, `CnChartWidget`) in a sensible order, not because of its
`<style scoped>` block. Copying styling would produce three pages that look
alike and still each own a private render path.

So the leading thing about process-mining is its **decomposition**, and it moves
from hand-written template to declared widgets (ADR-036/ADR-049). Once all three
declare widgets, the shared dashboard grid supplies the styling and the three
surfaces converge because they render through the same code, not because three
stylesheets were aligned.

### Why doorlooptijd is the hardest of the three despite already being `type: dashboard`

`/doorlooptijd` looks compliant and is not. Its config is one widget at
`gridWidth: 12, gridHeight: 12` whose slot renders `DoorlooptijdDashboard.vue` —
594 lines that draw a complete dashboard including its own `<h2>`. The page type
draws a dashboard; the widget draws a second one inside it. That is
`hydra-gate-dashboard-antipattern` (hydra#316), and it is why the page has two
heading levels and a doubled header.

Unnesting means the component stops being a page and becomes several widgets.
That is more work than converting the two `custom` pages, which at least have
one honest header each.

## Decision 2 — the in-app `/settings` page is a security fix, not deduplication

`AdminRoot.vue` is mounted twice: by `templates/settings/admin.php` at
`/settings/admin/procest` (correct) and by the manifest slot `section-admin` at
`/apps/procest/settings` (not). The second path reaches an administration
component through the in-app router, which does not apply the settings
framework's server-side checks. ADR-004 makes this a hard rule and
`hydra-gate-admin-router` exists to catch it.

Consequence for sequencing: this is not "delete a duplicate page when
convenient". It goes first in block B, and tenant-onboarding (B3) depends on it
because both edit `AdminRoot.vue`.

## Decision 3 — retire pages, keep data

Every retirement in this change removes a *page*, never a schema. `location`,
`bezwaaradviescommissie` and `parafeerroute` objects keep existing and stay
reachable from the surfaces that own them. `retire-status-history-page` is the
precedent: page and menu entry gone, objects untouched, e2e specs rewritten to
assert absence rather than deleted.

One nuance on `/settings/locations`: `/cases` already declares
`viewModes: ["table","cards","map"]` with a `mapConfig`, so the map capability
is genuinely present. What the standalone index additionally offered was a
*flat administrative list* of location objects. Before deleting it, confirm the
map view covers the real use — if an administrator needs to find an orphaned
location object, the map does not show it.

## Decision 4 — every cross-app move is two PRs, never one

Land in the owner app, then retire here. Never both in one PR: between merge and
deploy the capability would exist nowhere. `move-portals-to-portaliq` already
uses this shape (provider first, in-app views second).

The retirement PR is also the cheap half. The expensive half is establishing
what the owner app is missing — procest's `VerwerkingenOverview.vue` already
talks to OpenRegister's `/api/avg`, so C1 is close to a pure deletion, whereas
C2's automatic actions must be re-expressed as flow definitions and is close to
a rebuild.

## Decision 5 — besluitvorming: finish what consume-decidesk-besluitvorming-leaf started

The active change `consume-decidesk-besluitvorming-leaf` already retires the
Besluitvorming nav group and surfaces decidesk's decisions as an integration
leaf on the case detail — the "besluitvorming as a widget on a case" half. It
deliberately keeps `/besluitvorming/agenda` and
`/besluitvorming/vergaderingen/:id` **routable** for deep links and e2e.

This change removes those two pages outright, because decidesk's `/agenda-items`
and `/meetings` are the owner. That makes D1 strictly *after* that change, not
parallel — retiring routes it deliberately preserved, before it has landed,
would conflict.

The leaf stays a **render-and-read** channel per ADR-066. It exposes no verb.
Anything procest needs decidesk to *do* travels as a typed event (ADR-041).

## Sequencing

```
A1..A4  ─┐
B1 → B2  ├─ procest-internal, no cross-app dependency, can start now
B1 → B3  │
B4, B5  ─┘

C1 → (OR lands gaps) → retire in procest
C2 → (OR flow definitions) → retire in procest
D1 → after consume-decidesk-besluitvorming-leaf → decidesk lands → retire here
D2, D3 → decidesk lands → retire here
E1 → hermiq lands → retire here
```

## Risks

- **Widget vocabulary may not cover every chart the three dashboards draw.** If a
  visualisation has no declarative equivalent, the honest outcome is a widget
  that wraps one component — not a page that reverts to `type: custom`. Record
  the gap against nc-vue rather than working around it here.
- **`/cases` map view may not fully replace the locations index** (Decision 3).
  Verify before deleting; if it does not, the page stays and B5 is dropped from
  this change with a reason.
- **Automatic actions may encode logic the flow engine cannot yet express.**
  C2 is the item most likely to reveal a missing OpenRegister capability. Treat a
  gap as an OpenRegister change, not as grounds to keep a second engine.
