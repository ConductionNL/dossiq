# Werkvoorraad Intelligent Queue Specification

## Purpose

Give case handlers a server-computed urgency signal over their assigned
work, and give coordinators a cross-handler workload view, without
reintroducing the bespoke "werkvoorraad" board that `my-work` deliberately
retired. The scoring logic lives in a single deterministic, unit-tested
service so the urgency signal is explainable (a score breakdown, not a
black box) and reusable beyond the My Work card list.

## ADDED Requirements

### Requirement: Deterministic Urgency Scoring [MVP]

The system MUST provide a pure, deterministic scoring function that maps
(deadline, priority, reference date) to an urgency tier, a numeric score,
and a score breakdown. Tiers, by business days until the resolved deadline:
`overdue` (< 0), `critical` (0–3), `warning` (4–7), `normal` (> 7 or no
deadline). The function MUST NOT perform I/O — it takes primitive inputs and
returns a value, so it is directly unit-testable.

#### Scenario: No deadline
- GIVEN an item with no resolvable deadline
- WHEN it is scored
- THEN its tier MUST be `normal` and `daysUntilDeadline` MUST be `null`

#### Scenario: Overdue tier
- GIVEN an item whose deadline is 2 business days in the past
- WHEN it is scored
- THEN its tier MUST be `overdue`

#### Scenario: Critical tier boundary
- GIVEN an item whose deadline is exactly 3 business days away
- WHEN it is scored
- THEN its tier MUST be `critical`
- AND an item whose deadline is exactly 4 business days away MUST be `warning`

#### Scenario: Warning tier boundary
- GIVEN an item whose deadline is exactly 7 business days away
- WHEN it is scored
- THEN its tier MUST be `warning`
- AND an item whose deadline is exactly 8 business days away MUST be `normal`

#### Scenario: Priority increases score within a tier
- GIVEN two items in the same urgency tier, one `priority: urgent` and one
  `priority: low`
- WHEN both are scored
- THEN the `urgent` item's score MUST be strictly higher

### Requirement: Personal Work Queue Endpoint [MVP]

The system MUST expose `GET /api/work-queue`, returning the authenticated
user's open cases (`assignee == uid`, `endDate` empty) and open tasks
(`assignee == uid`, non-terminal status), each annotated with its urgency
tier, score, and score breakdown, sorted by score descending. Unauthenticated
callers MUST receive 401.

@e2e exclude Requires pre-seeded per-user cases/tasks with varying deadlines;
data-dependent list contents are not assertable without seeded fixtures.

#### Scenario: Only the caller's open items are returned
- GIVEN user "Jan" is assignee on 2 open cases and 1 closed case, and user
  "Marie" is assignee on 1 open case
- WHEN Jan calls `GET /api/work-queue`
- THEN the response MUST contain exactly Jan's 2 open cases
- AND MUST NOT contain Jan's closed case or Marie's case

#### Scenario: Unauthenticated call is rejected
- GIVEN no authenticated session
- WHEN `GET /api/work-queue` is called
- THEN the system MUST respond 401

### Requirement: Coordinator Workload Endpoint [MVP]

The system MUST expose `GET /api/work-queue/workload`, returning per-handler
open-case counts across all cases, gated to the coordinator role (Nextcloud
admin group membership, mirroring the existing coordinator model in
`SubstitutionController`). Non-coordinators MUST receive 403; unauthenticated
callers MUST receive 401.

@e2e exclude Requires an admin-group test user and pre-seeded multi-handler
case data; not assertable without seeded fixtures.

#### Scenario: Coordinator sees per-handler counts
- GIVEN an admin-group user, and open cases distributed across 3 handlers
- WHEN the admin calls `GET /api/work-queue/workload`
- THEN the response MUST list each handler with their open-case count

#### Scenario: Non-coordinator is denied
- GIVEN an authenticated non-admin user
- WHEN they call `GET /api/work-queue/workload`
- THEN the system MUST respond 403

### Requirement: Urgency-Aware My Work Sorting [MVP]

The My Work card list MUST offer a sort toggle between "Urgency" (default)
and "Newest", implemented as `sortKey`/`sortOrder` on the existing
`CnIndexPage` self-fetch (so sidebar search/facet filtering keeps working
unmodified). "Urgency" MUST order by the case's own `deadline` field
ascending; "Newest" MUST order by `startDate` descending.

@e2e exclude Requires multiple assigned cases with distinct deadlines/start
dates; ordering assertions are data-dependent.

#### Scenario: Urgency is the default sort
- GIVEN Jan opens My Work for the first time
- THEN the list MUST be sorted by nearest deadline first

#### Scenario: Toggling to Newest re-sorts
- GIVEN Jan is viewing My Work sorted by Urgency
- WHEN Jan selects the "Newest" sort option
- THEN the list MUST re-sort by case start date, most recent first

### Requirement: Urgency Chip on Cards [MVP]

Each My Work card MUST show an urgency chip sourced from
`GET /api/work-queue`, using only Nextcloud CSS variables (no hardcoded
colours): `overdue` renders in the error colour, `critical` in the warning
colour, `warning` in a softer warning style, and `normal` renders no chip.

#### Scenario: Overdue chip
- GIVEN a case whose work-queue tier is `overdue`
- THEN its card MUST show a chip styled with `--color-error`

#### Scenario: Critical chip
- GIVEN a case whose work-queue tier is `critical`
- THEN its card MUST show a chip styled with `--color-warning`

#### Scenario: Normal tier shows no chip
- GIVEN a case whose work-queue tier is `normal`
- THEN its card MUST NOT show an urgency chip

### Requirement: Coordinator Workload Summary [MVP]

The system MUST render a compact workload summary bar in My Work, showing
each handler's open-case count, whenever `GET /api/work-queue/workload`
succeeds (the caller is a coordinator). When the call fails (403 for a
non-coordinator), the system MUST render My Work without the summary and
MUST NOT show an error.

@e2e exclude Requires an admin-group test session and multi-handler seed
data; not assertable without seeded fixtures.

#### Scenario: Coordinator sees the summary
- GIVEN an admin-group user opens My Work
- THEN a workload summary bar MUST render showing per-handler counts

#### Scenario: Non-coordinator sees no summary and no error
- GIVEN a non-admin user opens My Work
- THEN no workload summary bar MUST render
- AND no error MUST be shown for the failed workload request
