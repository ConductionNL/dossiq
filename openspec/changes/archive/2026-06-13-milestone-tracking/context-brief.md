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



## Design

# Milestone Tracking Design

## Architecture
Milestones are a thin business-friendly layer over the existing status pipeline. Milestone *definitions* live on the `caseType` object as an ordered array. Milestone *records* are OpenRegister objects linked to a case, created when a milestone is reached and updated when reversed. The existing `StatusTimeline.vue` continues to show technical status; the new milestone components show business progress alongside it.

Three reach mechanisms feed the same write path:
1. **n8n webhook** — workflows POST to `/api/cases/{id}/milestones/trigger` with an event name matching a milestone's `triggerEvent`.
2. **Status transition** — when a case status changes, `MilestoneService::onStatusChanged()` maps mapped milestones to reached.
3. **Manual** — case workers click a milestone dot and confirm; coordinators can reverse with a mandatory justification.

## Data Model

### Milestone Definition (on caseType)
Array of objects with: `identifier` (slug), `label` (Dutch), `order` (int), `description`, `triggerEvent` (optional), `mappedStatusType` (optional), `expectedDurationWorkingDays` (optional, integer), `dependsOn` (array of identifiers).

### MilestoneRecord (OpenRegister schema)
- `case` — reference to parent case.
- `milestoneIdentifier` — slug from caseType config.
- `reached` — boolean.
- `reachedAt` — datetime.
- `triggerSource` — enum `manual` | `workflow` | `status_transition`.
- `triggeredBy` — user UID or n8n execution ID.
- `reversedAt` — datetime, nullable.
- `reversalReason` — string, nullable.

Schema registered as `milestone_record_schema` in `SettingsService::SLUG_TO_CONFIG_KEY`.

## Components
1. **MilestoneProgress.vue** — horizontal step indicator on case detail (below the status card). Renders dots per milestone, green for reached, amber within 1 day of deadline, red overdue, current milestone larger. Provides keyboard navigation and `role="progressbar"` with ARIA labels.
2. **MilestoneProgressBar.vue** — compact 2/6-style bar for case list rows.
3. **MilestoneConfigTab.vue** — drag-and-drop reorder, label + identifier editor, optional `mappedStatusType` and `triggerEvent` selectors; integrated as a new tab in `CaseTypeDetail.vue`.
4. **MilestoneDetailPanel.vue** — popover on dot click showing reach metadata and reversal history.
5. **MilestoneFunnelWidget.vue** — dashboard panel rendering cases-at-stage counts as a funnel.
6. **MilestoneKpiCard.vue** — "Mijlpaalvoortgang" KPI card on dashboard.

## Backend
- `MilestoneService` — `reach()`, `reverse()`, `onStatusChanged()`, `evaluateDependencies()`, `computeDurations()`, `computeProgress()`, `findOverdue()`, `findBottlenecks()`.
- `MilestoneController` — `GET /api/cases/{id}/milestones`, `POST /api/cases/{id}/milestones/{slug}/reach`, `POST /api/cases/{id}/milestones/{slug}/reverse`, `POST /api/cases/{id}/milestones/trigger`, `GET /api/public/cases/{token}/milestones`, plus a ZGW-compatible representation embedded in the existing `ZrcController` status history response.
- Reversal authorization: only users with role `coordinator` on the case can reverse a milestone; enforced in `MilestoneService::reverse()` and double-checked at controller.

## Public/Citizen API
Public endpoint strips `triggerSource`, `triggeredBy`, identifiers, and durations. Returns labels, order, reached, and a localized `currentStep` like "Stap 3 van 6: Inhoudelijke beoordeling".

## Risks & Mitigations
- Drift between status types and milestones when both are configured — the mapping is optional and the spec explicitly allows them to coexist; document the recommended mapping pattern per zaaktype.
- Reorder breaks historical analytics — milestone records persist their identifier and timestamp; reorder only affects new cases, historical durations recompute from records.
- Citizen API leakage — explicit allow-list of fields in the public serializer; covered by a serializer unit test.

## Standards
CMMN 1.1 (Milestone PlanItem, sentries), Flowable MilestoneInstance, ZGW Zaken API (status history mapping), GEMMA voortgangsbewaking / fase concept, WCAG 2.1 AA (progressbar role, ARIA labels, non-color indicators), Schema.org `Event` and `ProgressStatus`.



## Tasks

# Tasks

- [ ] TASK-MT-01: Add `milestoneRecord` schema to `procest_register.json` and register `milestone_record_schema` in `SettingsService::SLUG_TO_CONFIG_KEY`; extend the `caseType` schema with a `milestones[]` array (identifier, label, order, description, triggerEvent, mappedStatusType, expectedDurationWorkingDays, dependsOn).
- [ ] TASK-MT-02: Implement `MilestoneService` with `reach`, `reverse`, `onStatusChanged`, `evaluateDependencies`, `computeDurations`, `computeProgress`, `findOverdue`, and `findBottlenecks`; enforce coordinator-only reversal.
- [ ] TASK-MT-03: Implement `MilestoneController` with authenticated, public/citizen-token, and ZGW-compatible endpoints; integrate the ZGW representation into the existing `ZrcController` status history.
- [ ] TASK-MT-04: Create `MilestoneProgress.vue` (case detail) and `MilestoneProgressBar.vue` (case list) with WCAG 2.1 AA ARIA roles, keyboard navigation, and non-color status indicators.
- [ ] TASK-MT-05: Create `MilestoneDetailPanel.vue` showing reach metadata and reversal history; wire it to dot clicks in `MilestoneProgress.vue`.
- [ ] TASK-MT-06: Add `MilestoneConfigTab.vue` to `CaseTypeDetail.vue` with drag-and-drop reorder, identifier/label editor, optional status-type mapping and trigger-event selector, and dependency picker with cycle validation.
- [ ] TASK-MT-07: Add `MilestoneKpiCard.vue` and `MilestoneFunnelWidget.vue` to `Dashboard.vue`; add milestone filter to the case list view.
- [ ] TASK-MT-08: Hook status-change events to `MilestoneService::onStatusChanged()` and document the n8n webhook contract for `/api/cases/{id}/milestones/trigger`.
- [ ] TASK-MT-09: Add the daily bottleneck-detection job (n8n or system cron) that flags cases lingering >1.5x the average inter-milestone duration and notifies the coordinator.
- [ ] TASK-MT-10: Add Dutch + English i18n for milestone UI, KPI labels, and notification templates.