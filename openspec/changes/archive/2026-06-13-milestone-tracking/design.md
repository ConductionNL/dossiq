# Design: Milestone Tracking Implementation

## Architecture

Milestones are a thin business-friendly layer over the existing status pipeline. Milestone *definitions* live on the `caseType` object as an ordered array. Milestone *records* are OpenRegister objects linked to a case, created when a milestone is reached and updated when reversed. The existing `StatusTimeline.vue` continues to show technical status; the new milestone components show business progress alongside it.

Three reach mechanisms feed the same write path:

1. **n8n webhook** — workflows POST to `/api/cases/{id}/milestones/trigger` with an event name matching a milestone's `triggerEvent`.
2. **Status transition** — when a case status changes, `MilestoneService::onStatusChanged()` automatically reaches mapped milestones.
3. **Manual** — case workers click a milestone dot and confirm; coordinators can reverse with a mandatory justification.

The design adheres to ADR-001 (data-layer): all milestone records are OpenRegister objects (no custom Entity/Mapper for domain data). Milestone definitions are stored on the `caseType` schema as structured config (like `referenceProcess`, not a separate schema). This avoids duplication and keeps the data model aligned with Procest's existing case-type metadata pattern.

## Data Model

### Milestone Definition (on caseType schema)

Array of objects with the following properties:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| identifier | string | Yes | Slug identifier (e.g., `aanvraag_ontvangen`) — used in API and as the primary key for tracking. |
| label | string | Yes | Dutch display name shown to end users (e.g., "Aanvraag ontvangen"). |
| order | integer | Yes | Position in the milestone sequence (1, 2, 3, ...). |
| description | string | No | Optional longer explanation visible on hover or in config UI. |
| triggerEvent | string | No | n8n workflow event name (e.g., `all_documents_received`). If provided, a POST to `/api/cases/{id}/milestones/trigger` with this event name will mark the milestone reached. |
| mappedStatusType | string | No | UUID reference to a statusType. When a case reaches this status, the milestone is automatically reached. |
| expectedDurationWorkingDays | integer | No | Working days expected from case start or previous milestone reach to this milestone. Used for deadline calculation and KPI analysis. |
| dependsOn | array[string] | No | Array of milestone identifiers that must be reached before this one (e.g., `["aanvraag_ontvangen", "documenten_compleet"]`). |

**Example zaaktype configuration:**

```json
{
  "milestones": [
    {
      "identifier": "aanvraag_ontvangen",
      "label": "Aanvraag ontvangen",
      "order": 1,
      "description": "Aanvraag is in het systeem ingevoerd.",
      "expectedDurationWorkingDays": 0
    },
    {
      "identifier": "documenten_compleet",
      "label": "Documenten compleet",
      "order": 2,
      "mappedStatusType": "uuid-of-volledigheid-status",
      "expectedDurationWorkingDays": 5,
      "dependsOn": ["aanvraag_ontvangen"]
    },
    {
      "identifier": "inhoudelijke_beoordeling",
      "label": "Inhoudelijke beoordeling",
      "order": 3,
      "triggerEvent": "assessment_started",
      "expectedDurationWorkingDays": 14,
      "dependsOn": ["documenten_compleet"]
    },
    {
      "identifier": "adviezen_ontvangen",
      "label": "Adviezen ontvangen",
      "order": 4,
      "expectedDurationWorkingDays": 21,
      "dependsOn": ["inhoudelijke_beoordeling"]
    },
    {
      "identifier": "besluit_genomen",
      "label": "Besluit genomen",
      "order": 5,
      "mappedStatusType": "uuid-of-besluit-status",
      "expectedDurationWorkingDays": 35,
      "dependsOn": ["adviezen_ontvangen"]
    },
    {
      "identifier": "beschikking_verzonden",
      "label": "Beschikking verzonden",
      "order": 6,
      "mappedStatusType": "uuid-of-verzonden-status",
      "expectedDurationWorkingDays": 36
    }
  ]
}
```

### MilestoneRecord (OpenRegister schema)

A new schema `milestoneRecord` stored in `procest_register.json`:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| case | string | Yes | Reference to the parent case. |
| milestoneIdentifier | string | Yes | Slug from the caseType's milestone configuration. |
| reached | boolean | Yes | Whether the milestone is reached (true) or reversed (false). |
| reachedAt | string (ISO 8601) | Yes | Timestamp when the milestone was first reached. |
| triggerSource | enum | Yes | How the milestone was reached: `manual` (user action), `workflow` (n8n webhook), or `status_transition` (automatic from status change). |
| triggeredBy | string | No | User UID (if manual), n8n execution ID (if workflow), or status record UUID (if status_transition). |
| reversedAt | string (ISO 8601) | No | Timestamp when the milestone was reversed (null if never reversed or if still reached). |
| reversalReason | string | No | Justification text provided by the user who reversed the milestone (required if reversed). |

**Schema registration:**

The schema is registered in `SettingsService::SLUG_TO_CONFIG_KEY` as:

```php
'milestone_record_schema' => [
    'register' => 'procest_register',
    'schema' => 'milestoneRecord',
    ...
]
```

### Seed Data Example

Three example milestones for zaaktype `omgevingsvergunning`:

```json
{
  "milestones": [
    {
      "identifier": "aanvraag_received",
      "label": "Aanvraag ontvangen",
      "order": 1,
      "expectedDurationWorkingDays": 0,
      "description": "Aanvraag is in het systeem ingevoerd."
    },
    {
      "identifier": "docs_complete",
      "label": "Documenten compleet",
      "order": 2,
      "expectedDurationWorkingDays": 5,
      "dependsOn": ["aanvraag_received"]
    },
    {
      "identifier": "decision_made",
      "label": "Besluit genomen",
      "order": 3,
      "expectedDurationWorkingDays": 35,
      "dependsOn": ["docs_complete"]
    }
  ]
}
```

And three example milestones for zaaktype `melding_openbare_ruimte` (simpler):

```json
{
  "milestones": [
    {
      "identifier": "melding_received",
      "label": "Melding ontvangen",
      "order": 1,
      "expectedDurationWorkingDays": 0
    },
    {
      "identifier": "being_processed",
      "label": "In behandeling",
      "order": 2,
      "expectedDurationWorkingDays": 3,
      "dependsOn": ["melding_received"]
    },
    {
      "identifier": "resolved",
      "label": "Opgelost",
      "order": 3,
      "expectedDurationWorkingDays": 10,
      "dependsOn": ["being_processed"]
    }
  ]
}
```

## Components

### Frontend Components

1. **MilestoneProgress.vue** — horizontal step indicator on case detail (below the status card). Renders dots per milestone:
   - Green dot: milestone reached.
   - Amber dot: milestone not reached, but within 1 working day of deadline.
   - Red dot: milestone overdue.
   - Grey dot: pending milestone.
   - Current milestone (highest reached or first pending) is larger/highlighted.
   - Keyboard navigation via arrow keys; focus-trappable.
   - `role="progressbar"` with `aria-valuenow`, `aria-valuemin`, `aria-valuemax`.
   - Each dot has `aria-label` describing milestone name and status.
   - Tooltips on hover show `label`, `reachedAt`, `triggerSource`, and who triggered it.

2. **MilestoneProgressBar.vue** — compact progress bar for case list rows (e.g., "2/6 Documenten compleet"):
   - Fraction label (e.g., "3/6").
   - Filled background proportional to progress (3/6 = 50%).
   - Current milestone label on the right.
   - Click to navigate to case detail and scroll to full milestone section.

3. **MilestoneConfigTab.vue** — new tab in `CaseTypeDetail.vue` for configuring milestones:
   - Drag-and-drop reordering (updates order numbers in real-time).
   - Add/remove milestone buttons.
   - For each milestone: identifier editor (slug validation), label editor (Dutch text), description field.
   - Optional dropdowns for `mappedStatusType` (select from zaaktype's status types) and `triggerEvent` (free text or dropdown of known n8n events).
   - Dependency picker: checkboxes for previously-defined milestones; client-side cycle detection (prevent A→B→A).
   - Save validates identifier uniqueness and dependency DAG.

4. **MilestoneDetailPanel.vue** — popover on dot click showing:
   - Milestone label, description, order (e.g., "3 van 6").
   - Reached date/time (if reached), trigger source (manual/workflow/status_transition), and who triggered it.
   - If reversal history exists: timestamp, reversal reason, and who reversed it.
   - "Reverse milestone" button (only visible to users with `coordinator` role on the case); shows a modal with required reason field.

5. **MilestoneKpiCard.vue** — dashboard panel showing:
   - "Mijlpaalvoortgang" (Milestone Progress) headline.
   - KPI: number of cases on track (all milestone deadlines met).
   - KPI: number of cases with overdue milestones.
   - Metric: on-time percentage (e.g., "87%").
   - Trend indicator (arrow up/down vs. previous period).

6. **MilestoneFunnelWidget.vue** — dashboard panel showing:
   - Funnel visualization of cases at each milestone stage.
   - Horizontal bar per milestone showing count and percentage of total.
   - Example: 100 cases → 30 at milestone 1, 25 at milestone 2, 20 at milestone 3, 15 at milestone 4, 10 completed.
   - Hover tooltip shows exact count and average duration between this milestone and next.

### Case List Enhancements

- Add milestone filter to the case list view's filter bar:
  - Filter options: "Mijlpaal: [Milestone Label] (bereikt)" and "Mijlpaal: [Milestone Label] (niet bereikt)".
  - Multiple selections (OR logic): "Showing cases at milestone 3 or beyond".
  - Integrates with existing `CnFilterBar` component.

## Backend

### MilestoneService

Stateless service (no instance state) with these public methods:

- **`reach(caseId, milestoneIdentifier, triggerSource, triggeredBy): MilestoneRecord`**
  - Validates milestone exists on the case's zaaktype.
  - Checks dependencies: all `dependsOn` milestones must be reached; if not, throws `DependencyException`.
  - Creates a new `milestoneRecord` object via `ObjectService::saveObject()` with `reached=true`, `reachedAt=now`, `triggerSource`, `triggeredBy`.
  - Returns the created record.

- **`reverse(caseId, milestoneIdentifier, reversalReason, userId): void`**
  - Validates user has `coordinator` role on the case; if not, throws `AuthorizationException`.
  - Loads the existing `milestoneRecord`.
  - Updates `reached=false`, `reversedAt=now`, `reversalReason` via `ObjectService::saveObject()`.
  - Triggers audit trail record (automatic via OpenRegister).

- **`onStatusChanged(caseId, newStatusTypeId): void`**
  - Called by a status-change event listener (wired in the case service or workflow event dispatcher).
  - Iterates over all milestones configured on the case's zaaktype.
  - For each milestone with `mappedStatusType == newStatusTypeId`, calls `reach()` with `triggerSource='status_transition'` and `triggeredBy=statusRecordId`.

- **`evaluateDependencies(caseId, milestoneIdentifier): DependencyResult`**
  - Returns object: `{ canReach: boolean, blockedBy: string[] }`.
  - `canReach` is true if all `dependsOn` milestones are already reached.
  - `blockedBy` lists identifiers of unmet dependencies.

- **`computeDurations(caseId): array`**
  - Loads all milestone records for the case, sorted by `reachedAt`.
  - Returns array of durations: `{ fromMilestone, toMilestone, durationWorkingDays, durationCalendarDays }`.
  - Working days exclude weekends and Dutch holidays (via `WorkingDayCalculator` helper).

- **`computeProgress(caseId): ProgressMetrics`**
  - Returns: `{ reachedCount, totalCount, percentage, currentMilestone: { identifier, label, order }, expectedDeadline, daysOverdue }`.

- **`findOverdue(zaaktypeId, threshold_days): array`**
  - Queries all active cases of the given zaaktype.
  - For each case, finds the highest reached milestone and checks if current date exceeds its expected deadline by `threshold_days`.
  - Returns array of `{ caseId, caseName, unreachedMilestone, daysOverdue }`.
  - Used by bottleneck-detection job.

- **`findBottlenecks(zaaktypeId, percentile): array`**
  - Computes average duration between each consecutive milestone pair across all *completed* cases of the zaaktype.
  - For each *active* case, checks if it's lingering between milestones longer than `percentile` (default 1.5x) of the average.
  - Returns array of `{ caseId, caseName, betweenMilestones: [from, to], durationDays, averageDays, percentileThreshold }`.
  - Used by bottleneck-detection job.

### MilestoneController

REST API under `/index.php/apps/procest/api/milestones`:

- **`GET /api/cases/{caseId}/milestones`** — Authenticated. Returns:
  ```json
  {
    "milestones": [
      {
        "identifier": "aanvraag_ontvangen",
        "label": "Aanvraag ontvangen",
        "order": 1,
        "reached": true,
        "reachedAt": "2026-03-01T10:30:00Z",
        "triggerSource": "manual",
        "triggeredBy": "user-123",
        "deadline": "2026-03-01",
        "reversedAt": null,
        "reversalReason": null
      },
      ...
    ],
    "progress": {
      "reached": 3,
      "total": 6,
      "percentage": 50
    },
    "durations": [
      { "fromMilestone": 1, "toMilestone": 2, "durationWorkingDays": 4 },
      { "fromMilestone": 2, "toMilestone": 3, "durationWorkingDays": 10 }
    ]
  }
  ```

- **`POST /api/cases/{caseId}/milestones/{milestoneIdentifier}/reach`** — Authenticated. Body:
  ```json
  {
    "reason": "Documents verified as complete by secretary."
  }
  ```
  - Calls `MilestoneService::reach()` with `triggerSource='manual'` and `triggeredBy=authenticated_user_id`.
  - Returns 200 and the created milestone record on success; 400 if dependencies blocked; 403 if unauthorized.

- **`POST /api/cases/{caseId}/milestones/{milestoneIdentifier}/reverse`** — Authenticated, requires coordinator role. Body:
  ```json
  {
    "reason": "Applicant submitted incomplete documents. Reverting to document collection phase."
  }
  ```
  - Calls `MilestoneService::reverse()`.
  - Returns 200 on success; 403 if not coordinator; 404 if milestone not found.

- **`POST /api/cases/{caseId}/milestones/trigger`** — Public (no auth). Body:
  ```json
  {
    "event": "all_documents_received",
    "executionId": "n8n-execution-uuid"
  }
  ```
  - n8n workflow calls this to trigger milestone reach.
  - Finds all milestones with `triggerEvent == event` and calls `reach()` for each.
  - Returns 200 and array of reached milestone records; 400 if no milestones match event.
  - IP allowlist: only accept from n8n instance (configured in app settings).

- **`GET /api/public/cases/{caseShareToken}/milestones`** — Public, requires valid share token. Returns:
  ```json
  {
    "currentStep": "Stap 3 van 6: Inhoudelijke beoordeling",
    "progress": { "reached": 3, "total": 6 },
    "milestones": [
      {
        "order": 1,
        "label": "Aanvraag ontvangen",
        "reached": true
      },
      ...
    ]
  }
  ```
  - Strips `identifier`, `triggerSource`, `triggeredBy`, `durations`, deadlines, and reversal details.

### ZGW Integration

- `ZrcController` status history endpoint (`GET /api/v1/statussen`) is extended to include milestone data as enriched status objects:
  ```json
  {
    "url": "https://api.example.com/api/v1/statussen/123",
    "zaak": "https://api.example.com/api/v1/zaken/456",
    "statustype": "https://api.example.com/api/v1/statustypen/789",
    "datumStatusGezet": "2026-03-15T14:30:00Z",
    "statustoelichting": "Inhoudelijke beoordeling gestart",
    "milestone": {
      "identifier": "inhoudelijke_beoordeling",
      "label": "Inhoudelijke beoordeling",
      "order": 3
    }
  }
  ```

### Authorization

- Milestone reversal is gated to users with `coordinator` role on the case.
- Enforcement happens in `MilestoneService::reverse()` via `AuthorizationService::checkRole()`.
- Double-checked in `MilestoneController::reverseAction()` before calling the service.

## n8n Webhook Contract

Workflows trigger milestones by POSTing to `/api/cases/{caseId}/milestones/trigger`:

```bash
curl -X POST https://procest.example.com/index.php/apps/procest/api/cases/zaak-123/milestones/trigger \
  -H "Content-Type: application/json" \
  -d '{
    "event": "all_documents_received",
    "executionId": "n8n-exec-uuid-456"
  }'
```

The workflow includes the case ID in the URL path (extracted from context) and the event name in the body. The endpoint responds with:

```json
{
  "reached": [
    {
      "identifier": "documenten_compleet",
      "label": "Documenten compleet",
      "reachedAt": "2026-03-05T09:15:00Z"
    }
  ],
  "message": "1 milestone(s) reached."
}
```

## Bottleneck Detection

A daily cron job (or scheduled n8n workflow) runs `MilestoneService::findBottlenecks()`:

1. Computes average inter-milestone duration for completed cases.
2. Checks active cases for ones lingering >1.5x the average.
3. Sends notifications to the coordinator of each bottleneck case:
   ```
   "5 zaken wachten langer dan gemiddeld op mijlpaal 'Inhoudelijke beoordeling' (gemiddeld 14 dagen, deze 22+ dagen)"
   ```
4. Logs results for audit trail.

## Reuse Analysis

Per ADR-001, this change reuses:

- **`ObjectService`** for all CRUD of `milestoneRecord` objects.
- **`AuthorizationService`** for coordinator role checks.
- **`StatusTimeline.vue`** visual reference (milestone component is a sibling, not a replacement).
- **`CaseTypeDetail.vue`** as the container for the new config tab.
- **`DeadlinePanel.vue`** (potential future reuse for milestone deadline visualization).
- **`CnFilterBar`** for the case list milestone filter.
- Existing `statusRecord` schema for mapping milestones to status changes.

No custom mappers, entities, or CRUD endpoints are built; all data flows through OpenRegister.

## Standards & Normalization

- **CMMN 1.1 (OMG)**: Milestone is a first-class PlanItem (section 5.4.8) with entry criteria (sentries).
- **Flowable CMMN**: `MilestoneInstance` entity with `reached` state and sentry-driven triggering.
- **ZGW Zaken API**: Status history extension per VNG spec; milestones modeled as enriched status objects.
- **GEMMA voortgangsbewaking**: Standardized progress-monitoring concept; milestones formalize the "fase" pattern.
- **Schema.org**: `Event` and `ProgressStatus` for semantic interoperability.
- **WCAG 2.1 AA**: Progressbar role, keyboard navigation, ARIA labels, non-color-dependent state indication.

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Drift between status types and milestones when both are configured | Mapping is optional; document recommended pattern per zaaktype. Example docs show 1:1 mapping (every status maps to a milestone) vs. subset mapping (only key statuses). |
| Reorder breaks historical analytics | Milestone records persist their identifier and timestamp; reorder only affects new cases. Historical durations recompute from records on-demand. |
| Citizen API leakage (internal details exposed) | Explicit allow-list of fields in the public serializer (`identifier`, `triggerSource`, `triggeredBy` stripped). Covered by unit test `PublicMilestoneSerializerTest`. |
| Bottleneck detection generates noise | Alert only if >3 cases affected; threshold configurable per tenant. |
| Dependency cycle (A → B → C → A) | Client-side validation in `MilestoneConfigTab.vue` prevents cycles during config. Server-side validation in `MilestoneService::evaluateDependencies()` rejects invalid dependency DAGs at reach time. |
