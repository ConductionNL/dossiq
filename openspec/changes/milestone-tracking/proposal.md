# Milestone Tracking Implementation

## Why
Technical workflow states (status types, internal step IDs) are meaningless to citizens, managers, and ketenpartners who only want to know "where is my case in the journey?". CMMN 1.1 solves this with first-class milestones; Flowable engines and Dimpact ZAC use them internally but never expose them to end users. Procest needs a business-friendly progress layer that translates the existing status pipeline into ordered, named milestones with timestamps, optional deadlines, dependencies, and progress visualizations that work in case detail, case list, dashboard, and the public citizen view.

## What Changes
1. New `milestoneRecord` schema in `procest_register.json` storing per-case milestone reach events with trigger source and audit data.
2. Milestone-set configuration stored as an ordered array on the existing `caseType` schema.
3. `MilestoneService` for milestone CRUD, automatic reach (n8n webhook, status transition), manual reach, reversal with justification, dependency enforcement, and duration calculation.
4. `MilestoneProgress.vue` step indicator on case detail and `MilestoneProgressBar.vue` compact bar in case list.
5. Milestone configuration tab in the CaseType admin (`CaseTypeDetail.vue`).
6. REST endpoints for authenticated and public/citizen milestone queries, plus a ZGW-compatible status representation.
7. Dashboard widgets: milestone completion-rate KPI card, funnel visualization, milestone filter in case list.
8. Bottleneck detection alerts when active cases linger between milestones beyond the average duration.

## Impact
- Cases get a "Voortgang" section alongside the existing `StatusTimeline` (both coexist).
- Coordinators receive overdue and bottleneck notifications.
- External systems can consume milestone data via the new endpoints; citizen portal can show stripped-down progress.

## Out of Scope
- BPMN/CMMN visual model import.
- Milestone-based contractual SLA enforcement.
- Per-user milestone notification preferences.
