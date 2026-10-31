---
status: done
openspec_changes:
  - dashboard-my-work-split
---

# My Work Landing Page Specification

## Purpose

The app's landing page answers "what do I do first today", not "how is the
team doing" — that second question is the Dashboard's (`openspec/specs/dashboard/spec.md`,
route `/dashboard`). A handler opening dossiq lands on My Work (route `/`)
and sees their open tasks, the soonest deadlines, and the open cases across
the team, in that order, without picking a menu item first.

This is a distinct surface from `openspec/specs/my-work/spec.md`'s
`/my-work` page ("Assigned to me" in the navigation) — that page is a case
index scoped to the signed-in user's assignments; this page is a
`CnDashboardPage` of three read-only widgets. Both are reachable from the
"My work" navigation group: the group's own label opens this landing page,
and its fold-out holds Queue, Assigned to me, All cases, Tasks and Workflow
board (`openspec/specs/add-work-queue/spec.md`).

## Data Sources

All three widgets query OpenRegister against the `dossiq` register, schema
`case`, except My work which reads the current user's tasks through
OpenRegister's task engine (`useEngineTaskStore`):
- **My work**: the current user's open tasks, soonest due first.
- **Deadlines**: open cases (`isFinalStatus: false`) whose deadline is today,
  overdue, or within the next 3 days, ordered by deadline.
- **Open cases**: the most recently started open cases across the team.

## Requirements

### Requirement: My Work is the default landing page

dossiq SHALL render My Work at route `/`, the path the app opens on and the
path an unmatched route redirects to.

#### Scenario: Opening the app lands on My Work

- **WHEN** a handler opens `/apps/dossiq/`
- **THEN** the page rendered is My Work, not the Dashboard

### Requirement: The My work navigation entry both opens and folds out

The "My work" navigation group SHALL be a single entry that opens the My Work
landing page when its label is clicked, and independently expands to show
Queue, Assigned to me, All cases, Tasks and Workflow board when its chevron
is clicked — one click must not require the other.

#### Scenario: Clicking the label navigates without requiring expansion

- **WHEN** a handler clicks the "My work" label in the navigation
- **THEN** the app navigates to `/` (My Work)
- **AND** the group's fold-out state is unchanged by that click

#### Scenario: Expanding still reveals the five work surfaces

- **WHEN** a handler clicks the "My work" chevron
- **THEN** the group expands to show Queue, Assigned to me, All cases, Tasks
  and Workflow board
- **AND** expanding does not navigate away from the current page

### Requirement: My Work carries three widgets, the Dashboard carries none of them

The three widgets below SHALL render in the My work view of My Work and SHALL
NOT render on the Dashboard. The Dashboard's KPI cards, status/type charts, and
Stalled Cases panel stay on `/dashboard`. The My team view of My Work MAY show
copies of the team-wide ones, and each copy MUST equal the Dashboard's
definition.

#### Scenario: My Work shows open tasks, deadlines and open cases
@e2e exclude A manifest property, asserted in dashboardWorkTables.spec.js and landingViews.spec.js.
- **WHEN** a handler views the My work view of My Work in the full structure
- **THEN** the view shows a "My work" widget of the current user's open tasks
- **AND** a "Deadlines" widget of cases due today, overdue, or within 3 days
- **AND** an "Open Cases" widget of the most recently started open cases

#### Scenario: The Dashboard no longer carries the personal-workload widgets
@e2e exclude A manifest property, asserted in dashboardWorkTables.spec.js and landingViews.spec.js.
- **WHEN** a handler views `/dashboard`
- **THEN** the page does NOT show the My work, Deadlines or Open Cases widgets

### Requirement: A reader arranges each landing page view for themselves

The landing page MUST declare `userLayout` together with its `appId`, its
`pageId` and its views, so a reader can arrange the widgets inside My work and
inside My team. Each view MUST keep its own arrangement for that reader. The
manifest MUST still decide which widgets a view holds.

#### Scenario: A reader arranges My team
@e2e exclude The per-view storage is tested in nextcloud-vue (CnPageViewsUserLayout.spec.js); dossiq asserts the declaration in dashboardUserLayout.spec.js, and the coordinator checks it live.
- **GIVEN** a reader on the My team view
- **WHEN** they move a widget in edit mode and reload the page
- **THEN** My team MUST show the widget where they put it
- **AND** My work MUST keep its own arrangement

### Requirement: My team shows the unclaimed work of the reader's own teams

The My team view of the landing page SHALL carry the Dashboard's
`your-teams-queue` preset unchanged, so its queue SHALL be the Queue page's
filter narrowed to `assignedGroup: @myGroups`.

#### Scenario: The team view and the Dashboard agree

- **GIVEN** the manifest
- **THEN** the first widget of the My team view SHALL equal the `your-teams-queue` preset
- **AND** its filter SHALL equal the Queue page's filter plus `assignedGroup: "@myGroups"`

@e2e exclude A manifest invariant, asserted in tests/vitest/landingViews.spec.js.

### Requirement: The landing page holds a My work view and a My team view

The landing page (route `/`) MUST declare two views, My work (`mine`) and My
team (`team`), and MUST open on My work when neither the address nor the
reader's stored choice names a view. My work MUST hold the reader's own
widgets. My team MUST hold widgets the dashboard already declares: the shared
queue, the team counts and the team charts. Neither view MAY render empty
where the page showed widgets before. The same My team view MUST be shown in
the simple and the full structure.

#### Scenario: A handler opens the app
@e2e exclude Needs a live instance with both structures; the views, their widgets and their layout are asserted in landingViews.spec.js, and the coordinator checks the page live.
- **GIVEN** a handler who never chose a view
- **WHEN** they open `/apps/dossiq/`
- **THEN** the landing page MUST show the My work view
- **AND** the switch MUST offer My work and My team

#### Scenario: A team lead switches to My team
@e2e exclude Same reason; the library's view switch is tested in nextcloud-vue.
- **GIVEN** the landing page on My work
- **WHEN** the team lead picks My team
- **THEN** the page MUST show the shared queue, the team counts and the team charts below the switch
- **AND** the address MUST carry `?view=team`
- **AND** the page MUST NOT navigate to another page

#### Scenario: Both views place every widget they declare
@e2e exclude A comparison of the built pages with the manifest, asserted in landingViews.spec.js.
- **GIVEN** the simple or the full structure
- **WHEN** the landing page is built
- **THEN** each view MUST place at least one widget
- **AND** every widget a view declares MUST be placed in that view, without overlap

### Requirement: The simple greeting switches views, not pages

In the simple structure the greeting MUST sit on the landing page's own grid
and MUST draw the My work | My team switch. Each option MUST name a view of the
landing page and MUST NOT name a route. The landing page MUST keep its header,
because the header holds the links to Your queue, Assigned to me and Close out
your day.

#### Scenario: The greeting draws the switch
@e2e exclude Asserted on the built simple manifest in simpleListAndDashboard.spec.js.
- **GIVEN** the simple structure
- **WHEN** the landing page is built
- **THEN** the greeting options MUST be `{ label: My work, view: mine }` and `{ label: My team, view: team }`
- **AND** the page header MUST still carry the three links
