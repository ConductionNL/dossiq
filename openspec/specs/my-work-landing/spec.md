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

The three widgets below SHALL render on My Work and SHALL NOT render on the
Dashboard. The Dashboard's KPI cards, status/type charts, and Stalled Cases
panel are unaffected by this split and stay on `/dashboard`.

#### Scenario: My Work shows open tasks, deadlines and open cases

- **WHEN** a handler views My Work
- **THEN** the page shows a "My work" widget of the current user's open tasks
- **AND** a "Deadlines" widget of cases due today, overdue, or within 3 days
- **AND** an "Open Cases" widget of the most recently started open cases

#### Scenario: The Dashboard no longer carries the personal-workload widgets

- **WHEN** a handler views `/dashboard`
- **THEN** the page does NOT show the My work, Deadlines or Open Cases widgets
