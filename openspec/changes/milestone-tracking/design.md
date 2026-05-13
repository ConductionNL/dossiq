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
