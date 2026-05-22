---
kind: feature
depends_on: []
chain: []
---

# Proposal: Milestone Tracking Implementation

**Status:** proposed
**Scope:** procest
**Owner:** Specter Intelligence — Procest team

## Why

Technical workflow states (status types, internal step IDs) are meaningless to citizens, managers, and ketenpartners who only want to know "where is my case in the journey?". CMMN 1.1 solves this with first-class milestones; Flowable engines and Dimpact ZAC use them internally but never expose them to end users. Procest needs a business-friendly progress layer that translates the existing status pipeline into ordered, named milestones with timestamps, optional deadlines, dependencies, and progress visualizations that work in case detail, case list, dashboard, and the public citizen view.

Procest's existing `StatusTimeline.vue` shows technical status transitions — useful for workflow analysts but opaque to end users. A citizen filing an environmental permit (`omgevingsvergunning`) doesn't understand "status changed to UserTask_0x3f2a"; they understand "we're reviewing your documents" or "we've made a decision". Milestones bridge this gap by mapping internal workflow states to human-readable checkpoints that matter to the case owner.

## What Changes

1. **New `milestoneRecord` schema** in `procest_register.json` storing per-case milestone reach events with trigger source and audit data.
2. **Milestone-set configuration** stored as an ordered array on the existing `caseType` schema (identifier, label, order, description, triggerEvent, mappedStatusType, expectedDurationWorkingDays, dependsOn).
3. **`MilestoneService`** for milestone CRUD, automatic reach (n8n webhook, status transition), manual reach, reversal with justification, dependency enforcement, and duration calculation.
4. **Frontend components**: `MilestoneProgress.vue` step indicator on case detail, `MilestoneProgressBar.vue` compact bar in case list.
5. **Milestone configuration UI** in the CaseType admin (`CaseTypeDetail.vue`).
6. **REST endpoints** for authenticated and public/citizen milestone queries, plus a ZGW-compatible status representation.
7. **Dashboard widgets**: milestone completion-rate KPI card, funnel visualization, milestone filter in case list.
8. **Bottleneck detection** alerts when active cases linger between milestones beyond the average duration.

## Impact

- **Cases display progress alongside status**: a new "Voortgang" section appears on case detail alongside the existing `StatusTimeline` (both coexist).
- **Coordinators get overdue and bottleneck notifications**: system alerts when cases exceed expected milestone duration.
- **External systems can consume milestone data**: new API endpoints enable citizen portals and ketenpartners to show progress without exposing internal workflow details.
- **Citizens see their case progress**: public API returns stripped-down milestone progress (Stap 3 van 6).
- **Managers gain analytics**: dashboard shows funnel of cases at each milestone, average duration per phase, and trend analysis.
- **No breaking changes to status system**: existing `statusRecord` and `StatusTimeline` continue unchanged; milestones are additive.

## Out of Scope

- BPMN/CMMN visual model import — milestone sets are configured via UI, not parsed from process models.
- Milestone-based contractual SLA enforcement — bottleneck alerts are advisory, not contractual.
- Per-user milestone notification preferences — notifications go to assigned case worker and coordinator; no opt-out.
- Workflow-triggered milestone creation — milestones are defined on the zaaktype, not created dynamically by workflows.

## Success Criteria

- Milestone progress is displayed on 100% of case detail and case list views.
- Public API correctly strips internal fields and returns localized step labels.
- Bottleneck detection runs daily without errors and alerts coordinator within 1 minute.
- Milestone reversal requires coordinator role and justification text.
- All milestone UI components meet WCAG 2.1 AA accessibility (progressbar role, ARIA labels, non-color indicators).
