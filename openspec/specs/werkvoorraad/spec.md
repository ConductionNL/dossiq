# Werkvoorraad (Work Queue) Specification

## Purpose

Werkvoorraad extends the existing My Work personal view with team-level work queue management. While My Work (`../my-work/spec.md`) shows a single user's assigned cases and tasks, Werkvoorraad provides team leads and managers with oversight of the full team workload: unassigned cases, workload distribution, bottlenecks, and reassignment capabilities.

**Tender demand**: 23% of tenders (16/69) explicitly require werkvoorraadlijsten and team overview. The capability is also implicit in the 86% that require "gebruikersbeheer en autorisatie" -- managers need to see and manage their team's work.
**Relationship to existing specs**: This spec EXTENDS `my-work` (personal view) and `task-management` (individual tasks). It does NOT replace them. Werkvoorraad adds the team/management layer on top.
**Standards**: CMMN 1.1 (work queue patterns), BPMN 2.0 (resource allocation)
**Feature tier**: V1 (team overview, unassigned queue, reassignment), V2 (workload balancing, capacity planning, SLA monitoring)

## Requirements

---

### REQ-WV-01: Team Work Queue

**Feature tier**: V1

The system MUST provide a team work queue showing all open cases and tasks for a team or afdeling.

#### Scenario WV-01a: Team overview for manager

- GIVEN manager "Teamleider Vergunningen" responsible for team members ["Jan", "Maria", "Pieter", "Anouk"]
- AND the team has 24 open cases and 45 active tasks distributed across members
- WHEN the teamleider views the Werkvoorraad
- THEN the system MUST display:
  - Total open cases (24) and tasks (45)
  - Per-member breakdown: Jan (8 cases, 12 tasks), Maria (6, 11), Pieter (5, 10), Anouk (5, 12)
  - Unassigned cases (0) and tasks (0)
- AND the teamleider MUST be able to click on a member to see their specific items

#### Scenario WV-01b: Unassigned work queue

- GIVEN 3 cases and 5 tasks that have no assignee
- WHEN the teamleider views the "Niet-toegewezen" tab
- THEN all unassigned items MUST be listed
- AND items MUST be sorted by deadline (nearest first)
- AND the teamleider MUST be able to assign items to team members from this view

---

### REQ-WV-02: Priority and Urgency Sorting

**Feature tier**: V1

The work queue MUST support sorting by priority, urgency (deadline proximity), and a combined urgency score.

#### Scenario WV-02a: Urgency-based sorting

- GIVEN a work queue with items:
  - Case A: priority high, deadline tomorrow
  - Case B: priority normal, deadline overdue by 3 days
  - Case C: priority urgent, deadline in 10 days
  - Case D: priority normal, no deadline
- WHEN sorted by urgency (default)
- THEN the order MUST be: B (overdue), A (due tomorrow + high priority), C (urgent), D (no deadline)

---

### REQ-WV-03: Filters and Views

**Feature tier**: V1

The work queue MUST support filtering by zaaktype, status, afdeling, handler, and deadline range.

#### Scenario WV-03a: Filter by zaaktype

- GIVEN a team handling cases of types "Omgevingsvergunning" (10), "Sloopmelding" (8), "Milieumelding" (6)
- WHEN the teamleider filters by "Omgevingsvergunning"
- THEN only the 10 omgevingsvergunning cases MUST be shown

#### Scenario WV-03b: Filter overdue items

- GIVEN 5 cases past their deadline
- WHEN the teamleider selects filter "Verlopen termijn"
- THEN only the 5 overdue cases MUST be shown
- AND each case MUST show the number of days overdue

#### Scenario WV-03c: Filter by afdeling (cross-team)

- GIVEN a manager responsible for multiple teams (Vergunningen + Toezicht)
- WHEN filtering by afdeling "Toezicht"
- THEN only cases and tasks assigned to Toezicht team members MUST be shown

---

### REQ-WV-04: Bulk Reassignment

**Feature tier**: V1

The system MUST support reassigning multiple cases or tasks at once, for example when a team member is absent.

#### Scenario WV-04a: Reassign all work from absent colleague

- GIVEN Maria is on sick leave with 6 open cases and 11 tasks
- WHEN the teamleider selects all of Maria's items and clicks "Herverdelen"
- THEN the system MUST allow selecting a target assignee (or "round-robin" across team)
- AND all selected items MUST be reassigned
- AND each reassignment MUST be recorded in the audit trail: "Herverdeeld van Maria naar [target] door [teamleider], reden: afwezigheid"

---

### REQ-WV-05: Deadline Monitoring and Escalation

**Feature tier**: V1

The system MUST actively monitor deadlines and alert when cases approach or pass their deadline.

#### Scenario WV-05a: Deadline warning notification

- GIVEN a case with deadline in 3 days and no status change in the last 5 days
- WHEN the daily deadline check runs
- THEN the handler AND the teamleider MUST receive a notification: "Zaak [identifier] nadert deadline (nog 3 dagen)"

#### Scenario WV-05b: Overdue escalation

- GIVEN a case 2 days past its deadline
- WHEN the daily deadline check runs
- THEN the teamleider MUST receive an escalation notification: "Zaak [identifier] is 2 dagen over de termijn"
- AND the case MUST appear in the "Verlopen" section of the werkvoorraad with a red indicator

---

### REQ-WV-06: Workload Statistics

**Feature tier**: V2

The system SHOULD provide workload statistics for capacity planning.

#### Scenario WV-06a: Team capacity overview

- GIVEN a team of 4 members with varying workloads
- WHEN the teamleider views the capacity overview
- THEN the system MUST show per member: open cases count, active tasks count, average processing time, cases completed this week
- AND members with significantly above-average workload MUST be highlighted

## Dependencies

- **My Work spec** (`../my-work/spec.md`): Personal view; Werkvoorraad adds the team layer.
- **Task Management spec** (`../task-management/spec.md`): Tasks are the atomic work items.
- **Case Management spec** (`../case-management/spec.md`): Cases are the primary work units.
- **Admin Settings spec** (`../admin-settings/spec.md`): Team/afdeling configuration.
- **OpenRegister**: All queries against `procest` register.

---

### Current Implementation Status

**Not implemented as a team-level feature.** The personal My Work view exists (`src/views/MyWork.vue`) but no team/manager werkvoorraad functionality has been built.

**Existing foundations that relate to this spec:**
- **My Work view**: `src/views/MyWork.vue` -- personal workload view showing cases and tasks assigned to the current user. This is the base that werkvoorraad extends with team oversight. Includes grouping by urgency, filter tabs, overdue highlighting, and show-completed toggle.
- **Dashboard widgets**: `lib/Dashboard/CasesOverviewWidget.php` and `src/views/widgets/CasesOverviewWidget.vue` -- shows case overview, could be extended for team statistics. `lib/Dashboard/OverdueCasesWidget.php` shows overdue cases.
- **Dashboard panels**: `src/views/dashboard/KpiCards.vue` shows KPI summary cards. `src/views/dashboard/StatusChart.vue` shows status distribution. `src/views/dashboard/OverduePanel.vue` shows overdue items.
- **Object store**: The `useObjectStore()` can filter by `assignee` and `status`, which could be used for team member workload queries.
- **Case assignee field**: Cases have an `assignee` field (Nextcloud user UID) in the `case` schema, enabling per-user workload queries.
- **Task assignee field**: Tasks have an `assignee` field, enabling per-user task queries.

**Not yet implemented:**
- **REQ-WV-01: Team work queue**: No team overview, no per-member breakdown, no unassigned items queue.
- **REQ-WV-02: Priority/urgency sorting**: The My Work view has urgency grouping, but no combined urgency score or team-level sorting.
- **REQ-WV-03: Filters and views**: No zaaktype filter, afdeling filter, or deadline range filter on a team-level view.
- **REQ-WV-04: Bulk reassignment**: No multi-select or bulk reassignment capability. Only individual handler reassignment exists in `ParticipantsSection.vue`.
- **REQ-WV-05: Deadline monitoring**: No automated daily deadline checks or notification triggers. Overdue display is purely visual (client-side calculation).
- **REQ-WV-06: Workload statistics (V2)**: No capacity overview, average processing time, or workload comparison.
- **Team/afdeling concept**: No concept of teams or departments (afdelingen) in the data model. Nextcloud groups exist but are not used for team scoping.

### Standards & References

- **CMMN 1.1**: Work queue patterns for distributing and managing case work items.
- **BPMN 2.0**: Resource allocation patterns for work distribution.
- **ZGW APIs**: The ZGW model does not define a werkvoorraad concept directly, but team-based case filtering is standard in Dutch zaaksystemen.
- **GEMMA**: Werkvoorraad is a standard component in the GEMMA reference architecture for zaakgericht werken.
- **Common Ground**: The werkvoorraad is typically a process-layer concern built on top of the ZGW information layer.
- **BIO**: Audit trail requirements for reassignment actions (who reassigned, when, why).

### Specificity Assessment

- **V1 requirements are well-specified** with concrete scenarios for team overview, filtering, bulk reassignment, and deadline monitoring.
- **Missing foundational concept**: The spec assumes a "team" or "afdeling" concept that does not exist in the current data model. How are teams defined? Nextcloud groups? A custom team entity in OpenRegister? A configuration in admin settings?
- **Open questions:**
  - How are teams defined and managed? (Nextcloud groups, custom OpenRegister objects, or admin settings?)
  - Who has access to the werkvoorraad view? (Only team leads/managers, or configurable via RBAC?)
  - Should the werkvoorraad be a separate navigation item or integrated into the My Work view with a "Team" tab?
  - How are notifications triggered for deadline monitoring? (n8n workflow, cron job, Nextcloud background job?)
  - What defines a "team member" for unassigned case routing?
  - Should bulk reassignment support "round-robin" distribution automatically?
