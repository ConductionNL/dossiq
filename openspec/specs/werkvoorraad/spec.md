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
