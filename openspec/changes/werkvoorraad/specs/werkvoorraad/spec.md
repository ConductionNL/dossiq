---
status: proposed
---

# Werkvoorraad (Work Queue) Specification

## Purpose

Werkvoorraad extends the existing My Work personal view with team-level work queue management. While My Work (`../my-work/spec.md`) shows a single user's assigned cases and tasks, Werkvoorraad provides team leads and managers with oversight of the full team workload: unassigned cases, workload distribution, bottlenecks, and reassignment capabilities.

**Tender demand**: 23% of tenders (16/69) explicitly require werkvoorraadlijsten and team overview. The capability is also implicit in the 86% that require "gebruikersbeheer en autorisatie" -- managers need to see and manage their team's work.
**Relationship to existing specs**: This spec EXTENDS `my-work` (personal view) and `task-management` (individual tasks). It does NOT replace them. Werkvoorraad adds the team/management layer on top.
**Standards**: CMMN 1.1 (work queue patterns), BPMN 2.0 (resource allocation), GEMMA werkvoorraad referentiecomponent
**Feature tier**: V1 (team overview, unassigned queue, reassignment), V2 (workload balancing, capacity planning, SLA monitoring)

**Competitive context**: Dimpact ZAC implements werkvoorraad as a Solr-backed worklist with separate routes for "mijn zaken" and "werkvoorraad zaken" (unassigned cases in user's groups). ZAC uses Keycloak groups for team scoping and OPA policies for access control. Flowable provides configurable work queues via CMMN case plan models with role-based task assignment. Procest should leverage Nextcloud groups for team scoping, avoiding the need for separate identity infrastructure.

## Requirements

---

### REQ-WV-01: Team Work Queue View

The system MUST provide a team work queue showing all open cases and tasks for a team or afdeling, accessible via a dedicated navigation entry.

**Feature tier**: V1


#### Scenario WV-01a: Team overview for manager

- GIVEN manager "Teamleider Vergunningen" responsible for team members ["Jan", "Maria", "Pieter", "Anouk"]
- AND the team has 24 open cases and 45 active tasks distributed across members
- WHEN the teamleider views the Werkvoorraad
- THEN the system MUST display:
  - Total open cases (24) and tasks (45) for the team
  - Per-member breakdown: Jan (8 cases, 12 tasks), Maria (6, 11), Pieter (5, 10), Anouk (5, 12)
  - Unassigned cases (0) and tasks (0)
- AND the teamleider MUST be able to click on a member to see their specific items
- AND the view MUST load within 3 seconds for teams of up to 20 members

#### Scenario WV-01b: Unassigned work queue

- GIVEN 3 cases and 5 tasks that have no assignee
- AND the cases belong to case types managed by the team "Vergunningen"
- WHEN the teamleider views the "Niet-toegewezen" tab
- THEN all unassigned items MUST be listed
- AND items MUST be sorted by deadline (nearest first)
- AND the teamleider MUST be able to assign items to team members from this view via a dropdown

#### Scenario WV-01c: Cross-team manager view

- GIVEN a manager responsible for multiple teams (Vergunningen + Toezicht)
- WHEN the manager opens the werkvoorraad
- THEN the system MUST show a team selector allowing switching between teams
- AND an "Alle teams" option MUST aggregate all teams the manager is responsible for
- AND team totals MUST be visible in the selector: "Vergunningen (24 zaken)" and "Toezicht (18 zaken)"

#### Scenario WV-01d: No team membership

- GIVEN a user who is not a teamleider and has no werkvoorraad access
- WHEN the user navigates to the werkvoorraad URL
- THEN the system MUST show an access denied message: "U heeft geen toegang tot de werkvoorraad. Neem contact op met uw beheerder."
- AND the navigation item MUST NOT appear for users without werkvoorraad permissions

#### Scenario WV-01e: Empty team queue

- GIVEN a team with no open cases or tasks
- WHEN the teamleider views the werkvoorraad
- THEN the system MUST display an empty state: "Geen openstaande zaken of taken voor dit team"
- AND KPI cards MUST show zeros for all metrics

---

### REQ-WV-02: Team Scoping via Nextcloud Groups

The system MUST use Nextcloud groups as the foundation for team scoping. A group is a team when it is configured as such in Procest admin settings.

**Feature tier**: V1


#### Scenario WV-02a: Configure team from Nextcloud group

- GIVEN Nextcloud groups "afd-vergunningen" (10 members) and "afd-toezicht" (8 members) exist
- WHEN the beheerder opens Procest admin settings and configures "afd-vergunningen" as a werkvoorraad team
- THEN the system MUST store the group ID as a team configuration
- AND case types MUST be assignable to this team
- AND members of "afd-vergunningen" MUST appear in the werkvoorraad member list

#### Scenario WV-02b: Teamleider role assignment

- GIVEN group "afd-vergunningen" is configured as a team
- WHEN the beheerder assigns user "Jan de Vries" as teamleider
- THEN Jan MUST have access to the werkvoorraad for "afd-vergunningen"
- AND Jan MUST be able to assign and reassign items within the team
- AND regular team members MUST NOT see the werkvoorraad (only My Work)

#### Scenario WV-02c: Dynamic group membership

- GIVEN "Maria" is added to Nextcloud group "afd-vergunningen"
- WHEN the werkvoorraad is next loaded
- THEN Maria MUST appear in the member list
- AND any unassigned cases for this team's case types MUST be assignable to Maria

---

### REQ-WV-03: Priority and Urgency Sorting

The work queue MUST support sorting by priority, urgency (deadline proximity), and a combined urgency score that weighs both factors.

**Feature tier**: V1


#### Scenario WV-03a: Urgency-based sorting

- GIVEN a work queue with items:
  - Case A: priority high, deadline tomorrow
  - Case B: priority normal, deadline overdue by 3 days
  - Case C: priority urgent, deadline in 10 days
  - Case D: priority normal, no deadline
- WHEN sorted by urgency (default)
- THEN the order MUST be: B (overdue), A (due tomorrow + high priority), C (urgent), D (no deadline)

#### Scenario WV-03b: Sort by handler workload

- GIVEN 4 team members with varying workloads: Jan (12 items), Maria (6 items), Pieter (9 items), Anouk (3 items)
- WHEN the teamleider sorts by "Werklast" (workload)
- THEN members MUST be ordered by total active items descending: Jan (12), Pieter (9), Maria (6), Anouk (3)
- AND a visual bar chart or indicator MUST show relative workload

#### Scenario WV-03c: Sort by days in current status

- GIVEN 3 cases in status "In behandeling" for different durations
  - Case X: 2 days in current status
  - Case Y: 15 days in current status
  - Case Z: 8 days in current status
- WHEN the teamleider sorts by "Dagen in huidige status"
- THEN the order MUST be: Y (15), Z (8), X (2)
- AND cases exceeding the average processing time for their type MUST be highlighted

---

### REQ-WV-04: Filters and Views

The work queue MUST support filtering by zaaktype, status, afdeling, handler, deadline range, and priority.

**Feature tier**: V1


#### Scenario WV-04a: Filter by zaaktype

- GIVEN a team handling cases of types "Omgevingsvergunning" (10), "Sloopmelding" (8), "Milieumelding" (6)
- WHEN the teamleider filters by "Omgevingsvergunning"
- THEN only the 10 omgevingsvergunning cases MUST be shown
- AND the filter MUST show counts per zaaktype for quick selection

#### Scenario WV-04b: Filter overdue items

- GIVEN 5 cases past their deadline
- WHEN the teamleider selects filter "Verlopen termijn"
- THEN only the 5 overdue cases MUST be shown
- AND each case MUST show the number of days overdue with severity coloring: 1-3 days (orange), 4+ days (red)

#### Scenario WV-04c: Filter by handler

- GIVEN the teamleider wants to see all work for "Pieter"
- WHEN selecting handler filter "Pieter"
- THEN only Pieter's cases and tasks MUST be shown
- AND the view MUST include both active and recently completed items (last 7 days)

#### Scenario WV-04d: Combine multiple filters

- GIVEN filters: zaaktype = "Omgevingsvergunning", status = "In behandeling", handler = unassigned
- WHEN all three filters are active simultaneously
- THEN only unassigned omgevingsvergunning cases in "In behandeling" status MUST be shown
- AND the active filters MUST be displayed as removable chips above the list

#### Scenario WV-04e: Save filter preset

- GIVEN the teamleider has configured a useful filter combination
- WHEN the teamleider clicks "Opslaan als weergave" and names it "Urgente vergunningen"
- THEN the preset MUST be stored per user
- AND the preset MUST appear in a quick-access dropdown for future use

---

### REQ-WV-05: Bulk Reassignment

The system MUST support reassigning multiple cases or tasks at once, for example when a team member is absent.

**Feature tier**: V1


#### Scenario WV-05a: Reassign all work from absent colleague

- GIVEN Maria is on sick leave with 6 open cases and 11 tasks
- WHEN the teamleider selects all of Maria's items and clicks "Herverdelen"
- THEN the system MUST allow selecting a target assignee (or "round-robin" across team)
- AND all selected items MUST be reassigned
- AND each reassignment MUST be recorded in the audit trail: "Herverdeeld van Maria naar [target] door [teamleider], reden: afwezigheid"

#### Scenario WV-05b: Round-robin distribution

- GIVEN 8 unassigned cases to distribute across 4 team members [Jan, Maria, Pieter, Anouk]
- AND current workloads are: Jan (5), Maria (3), Pieter (4), Anouk (2)
- WHEN the teamleider selects "Gelijkmatig verdelen" (balanced distribution)
- THEN the system MUST distribute cases to balance workloads: Anouk gets 3, Maria gets 2, Pieter gets 2, Jan gets 1
- AND each assignment MUST be recorded in the audit trail

#### Scenario WV-05c: Partial reassignment

- GIVEN the teamleider selects 3 of Maria's 6 cases using checkboxes
- WHEN clicking "Toewijzen aan" and selecting "Pieter"
- THEN only the 3 selected cases MUST be reassigned to Pieter
- AND Maria MUST retain the other 3 cases

#### Scenario WV-05d: Reassignment with reason

- GIVEN the teamleider reassigns cases from Maria to Pieter
- WHEN the reassignment dialog opens
- THEN a reason field MUST be presented (optional but recommended)
- AND common reasons MUST be available as quick-select: "Afwezigheid", "Capaciteit", "Expertise", "Anders"
- AND the reason MUST be stored in the audit trail

---

### REQ-WV-06: Deadline Monitoring and Escalation

The system MUST actively monitor deadlines and alert when cases approach or pass their deadline.

**Feature tier**: V1


#### Scenario WV-06a: Deadline warning notification

- GIVEN a case with deadline in 3 days and no status change in the last 5 days
- WHEN the daily deadline check runs (Nextcloud background job)
- THEN the handler AND the teamleider MUST receive a Nextcloud notification: "Zaak [identifier] nadert deadline (nog 3 dagen)"
- AND the notification MUST link to the case detail page

#### Scenario WV-06b: Overdue escalation

- GIVEN a case 2 days past its deadline
- WHEN the daily deadline check runs
- THEN the teamleider MUST receive an escalation notification: "Zaak [identifier] is 2 dagen over de termijn"
- AND the case MUST appear in the "Verlopen" section of the werkvoorraad with a red indicator
- AND the notification MUST NOT be sent again for the same case on subsequent days (only on day 1 overdue)

#### Scenario WV-06c: Configurable warning thresholds

- GIVEN the beheerder configures warning thresholds per case type:
  - Omgevingsvergunning: warn at 5 days before deadline
  - Sloopmelding: warn at 3 days before deadline
- WHEN a case approaches the configured threshold
- THEN the notification MUST fire at the configured interval, not a global default

#### Scenario WV-06d: SLA indicator on queue items

- GIVEN a case type "Omgevingsvergunning" with processing deadline of 8 weeks
- AND a case has been open for 6 weeks (75% of deadline consumed)
- WHEN the case appears in the werkvoorraad
- THEN it MUST display a progress indicator showing 75% time consumed
- AND the indicator MUST use color coding: green (0-60%), orange (60-85%), red (85-100%), dark red (>100%)

#### Scenario WV-06e: Weekly deadline summary

- GIVEN a team with 3 cases due this week and 2 cases overdue
- WHEN Monday morning arrives (configurable day)
- THEN the teamleider MUST receive a summary notification: "Werkvoorraad weekoverzicht: 3 zaken deze week, 2 verlopen"
- AND the summary MUST be optional (configurable in user settings)

---

### REQ-WV-07: Werkvoorraad Dashboard KPIs

The werkvoorraad MUST display key performance indicators at the top of the view, providing at-a-glance team health metrics.

**Feature tier**: V1


#### Scenario WV-07a: Team KPI cards

- GIVEN a team with 24 open cases, 3 overdue, 12 completed this week, average processing time 14 days
- WHEN the teamleider views the werkvoorraad
- THEN KPI cards MUST display: "Open zaken" (24), "Verlopen" (3, in red), "Afgehandeld deze week" (12), "Gem. doorlooptijd" (14 dagen)
- AND each card MUST be clickable to filter the queue to that subset

#### Scenario WV-07b: Trend indicators

- GIVEN last week the team had 28 open cases and this week 24
- WHEN the KPI cards render
- THEN the "Open zaken" card MUST show a trend arrow: down arrow with "-4" indicating improvement
- AND the "Verlopen" card MUST show trend compared to last week

#### Scenario WV-07c: Case type distribution

- GIVEN the team handles 3 case types with varying volumes
- WHEN the teamleider views the werkvoorraad dashboard
- THEN a distribution chart MUST show: "Omgevingsvergunning" (10), "Sloopmelding" (8), "Milieumelding" (6)
- AND the chart MUST use the existing `StatusChart.vue` component pattern

---

### REQ-WV-08: Workload Statistics

The system SHALL provide workload statistics for capacity planning and performance monitoring.

**Feature tier**: V2


#### Scenario WV-08a: Team capacity overview

- GIVEN a team of 4 members with varying workloads
- WHEN the teamleider views the capacity overview
- THEN the system MUST show per member: open cases count, active tasks count, average processing time, cases completed this week
- AND members with significantly above-average workload (>150% of mean) MUST be highlighted in orange

#### Scenario WV-08b: Historical throughput

- GIVEN the team has been active for 3 months
- WHEN the teamleider views the "Doorlooptijd" (throughput) tab
- THEN the system MUST show a chart with: cases opened per week, cases closed per week, average backlog size
- AND the chart MUST allow selecting different time ranges: 1 week, 1 month, 3 months, 6 months

#### Scenario WV-08c: Per-case-type processing time

- GIVEN case type "Omgevingsvergunning" has a legal deadline of 8 weeks
- AND the team's average processing time for this type is 5.2 weeks
- WHEN the teamleider views case type statistics
- THEN the system MUST show: average processing time (5.2 weeks), legal deadline (8 weeks), percentage within deadline (92%), number completed (48)

---

### REQ-WV-09: Export and Reporting

The werkvoorraad MUST support exporting filtered queue data for external reporting and management meetings.

**Feature tier**: V2


#### Scenario WV-09a: CSV export of current view

- GIVEN the teamleider has filtered the werkvoorraad to show overdue cases
- WHEN clicking the "Exporteren" button
- THEN the system MUST generate a CSV file with columns: zaakidentificatie, titel, zaaktype, status, behandelaar, startdatum, deadline, dagen verlopen
- AND the export MUST respect the current filter and sort order

#### Scenario WV-09b: PDF summary report

- GIVEN the teamleider wants a weekly report for management
- WHEN clicking "Rapport genereren"
- THEN the system MUST generate a PDF containing: KPI summary, overdue cases list, workload distribution per member, case type distribution chart
- AND the report MUST include the date range and team name

---

### REQ-WV-10: Real-Time Updates

The werkvoorraad SHALL update in near-real-time when cases are created, assigned, or status-changed by other users.

**Feature tier**: V2


#### Scenario WV-10a: Case assigned by another user

- GIVEN the teamleider has the werkvoorraad open showing 3 unassigned cases
- AND another behandelaar assigns one of those cases to themselves via the case detail page
- WHEN the werkvoorraad refreshes (polling every 30 seconds or WebSocket)
- THEN the unassigned count MUST update from 3 to 2
- AND the assigned case MUST move to the correct team member's column

#### Scenario WV-10b: New case created

- GIVEN a new case is created via DSO intake that matches the team's case types
- WHEN the werkvoorraad refreshes
- THEN the new case MUST appear in the unassigned queue
- AND the "Open zaken" KPI MUST increment

---

### REQ-WV-11: Accessibility

The werkvoorraad MUST meet WCAG AA accessibility requirements.

**Feature tier**: V1


#### Scenario WV-11a: Screen reader navigation

- GIVEN a screen reader user navigating the werkvoorraad
- THEN all interactive elements (tabs, filters, assignment dropdowns, checkboxes) MUST have appropriate ARIA labels
- AND the KPI cards MUST announce their values: "Open zaken: 24"
- AND table rows MUST be navigable via keyboard (Tab/Arrow keys)

#### Scenario WV-11b: Keyboard bulk selection

- GIVEN a keyboard-only user viewing the werkvoorraad
- WHEN pressing Space on a row to select it, then Shift+Space to extend selection
- THEN the selection MUST work identically to mouse-based checkbox selection
- AND the "Herverdelen" action MUST be triggerable via keyboard

## Dependencies

- **My Work spec** (`../my-work/spec.md`): Personal view; Werkvoorraad adds the team layer.
- **Task Management spec** (`../task-management/spec.md`): Tasks are the atomic work items.
- **Case Management spec** (`../case-management/spec.md`): Cases are the primary work units.
- **Admin Settings spec** (`../admin-settings/spec.md`): Team/afdeling configuration, warning thresholds.
- **Dashboard spec** (`../dashboard/spec.md`): Shared KPI card and chart components.
- **OpenRegister**: All queries against `procest` register, filtered by assignee and team membership.
- **Nextcloud Groups**: Team scoping uses Nextcloud's `IGroupManager` for group membership queries.

---

### Current Implementation Status

**Not implemented as a team-level feature.** The personal My Work view exists (`src/views/MyWork.vue`) but no team/manager werkvoorraad functionality has been built.

**Existing foundations that relate to this spec:**
- **My Work view**: `src/views/MyWork.vue` -- personal workload view showing cases and tasks assigned to the current user. Includes grouping by urgency (overdue, due this week, upcoming, no deadline), filter tabs (all/cases/tasks), overdue highlighting, and show-completed toggle. This is the base UI pattern that werkvoorraad extends with team oversight.
- **Dashboard widgets**: `lib/Dashboard/CasesOverviewWidget.php` and `src/views/widgets/CasesOverviewWidget.vue` -- shows case overview, could be extended for team statistics. `lib/Dashboard/OverdueCasesWidget.php` shows overdue cases with severity-based coloring.
- **Dashboard panels**: `src/views/dashboard/KpiCards.vue` shows KPI summary cards (open cases, new today, overdue, completed this month, avg processing days, my tasks, tasks due today). `src/views/dashboard/StatusChart.vue` shows status distribution. `src/views/dashboard/OverduePanel.vue` shows overdue items with days-overdue count.
- **Object store**: The `useObjectStore()` can filter by `assignee` and `status`, which could be used for team member workload queries.
- **Case assignee field**: Cases have an `assignee` field (Nextcloud user UID) in the `case` schema, enabling per-user workload queries.
- **Task assignee field**: Tasks have an `assignee` field, enabling per-user task queries.
- **Notification service**: `lib/Service/NotificatieService.php` provides notification infrastructure for deadline alerts.

**Not yet implemented:**
- **REQ-WV-01: Team work queue view**: No team overview, no per-member breakdown, no unassigned items queue.
- **REQ-WV-02: Team scoping**: No concept of teams or departments (afdelingen) in the data model. Nextcloud groups exist but are not used for team scoping.
- **REQ-WV-03: Priority/urgency sorting**: The My Work view has urgency grouping, but no combined urgency score or team-level sorting.
- **REQ-WV-04: Filters and views**: No zaaktype filter, afdeling filter, or deadline range filter on a team-level view.
- **REQ-WV-05: Bulk reassignment**: No multi-select or bulk reassignment capability. Only individual handler reassignment exists in `ParticipantsSection.vue`.
- **REQ-WV-06: Deadline monitoring**: No automated daily deadline checks or notification triggers. Overdue display is purely visual (client-side calculation in `OverduePanel.vue`).
- **REQ-WV-07: Werkvoorraad KPIs**: Dashboard KPIs exist but are personal-scoped, not team-scoped.
- **REQ-WV-08: Workload statistics (V2)**: No capacity overview, average processing time, or workload comparison.
- **REQ-WV-09: Export (V2)**: No CSV or PDF export functionality.
- **REQ-WV-10: Real-time updates (V2)**: No polling or WebSocket for live updates.
- **REQ-WV-11: Accessibility**: Existing My Work view needs WCAG audit.

### Standards & References

- **CMMN 1.1**: Work queue patterns for distributing and managing case work items.
- **BPMN 2.0**: Resource allocation patterns for work distribution.
- **ZGW APIs**: The ZGW model does not define a werkvoorraad concept directly, but team-based case filtering is standard in Dutch zaaksystemen. Dimpact ZAC implements separate `/zaken/werkvoorraad` and `/zaken/mijn` worklist routes.
- **GEMMA**: Werkvoorraad is a standard component in the GEMMA reference architecture for zaakgericht werken.
- **Common Ground**: The werkvoorraad is typically a process-layer concern built on top of the ZGW information layer.
- **BIO**: Audit trail requirements for reassignment actions (who reassigned, when, why).
- **WCAG 2.1 AA**: Accessibility requirements for government applications.
- **Nextcloud OCP**: `IGroupManager` for group membership queries, `INotificationManager` for deadline notifications.

### Specificity Assessment

- **V1 requirements are well-specified** with concrete scenarios for team overview, team scoping, filtering, bulk reassignment, deadline monitoring, and KPIs.
- **Team scoping is now defined**: Teams are Nextcloud groups configured as werkvoorraad teams in admin settings. Teamleiders are designated users with werkvoorraad access.
- **Resolved open questions:**
  - Teams are defined via Nextcloud groups (REQ-WV-02).
  - Access is controlled by teamleider role assignment (REQ-WV-02b).
  - Werkvoorraad is a separate navigation item, not a tab in My Work (REQ-WV-01).
  - Notifications use Nextcloud background jobs (REQ-WV-06a).
  - Team membership is determined by Nextcloud group membership (REQ-WV-02c).
  - Round-robin distribution uses workload-aware balancing (REQ-WV-05b).
