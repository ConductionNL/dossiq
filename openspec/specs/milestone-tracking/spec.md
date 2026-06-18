---
retrofit: true
status: done
note: >-
  Demoted done->partial (2026-06-14, honest-status sweep): backend is built but
  the entire milestone Vue UI (progress indicator, config tab, dashboard widgets)
  plus several backend hooks remain deferred — see below.
  Core milestone surface is live: milestoneDefinition + milestoneRecord
  schemas registered (register.d/65-milestone-tracking.json + SLUG_TO_CONFIG_KEY),
  MilestoneService (progress/reach/reverse/findStalledCases) and
  MilestoneController (authenticated progress/mark/reverse) shipped, and the
  daily BottleneckDetectionJob flags cases stalled past a milestone deadline
  and notifies the assigned worker. Deferred (tracked in the change tasks.md):
  the public n8n-trigger + share-token endpoints, ZGW status-history
  enrichment, the status-transition auto-reach hook, dependency-DAG
  enforcement, and the milestone Vue UI (progress indicator, config tab,
  dashboard widgets) — UI/cross-app follow-ups.
---

# Milestone Tracking Specification

## Purpose

@e2e exclude Milestone tracking is V1; milestone API endpoints are covered by PHPUnit, not Playwright.

Translate technical workflow state into business-friendly milestone markers per case: configurable milestone definitions per case type, per-case progress aggregation (reached/total/percentage), explicit marking (with origin), reversal (with audit reason), and a placeholder hook for cross-case duration analytics.
## Requirements

### REQ-001: Milestone REST endpoints (progress / mark / reverse)

The system SHALL expose three `@NoAdminRequired` JSON endpoints on `MilestoneController`: `progress(caseId, caseTypeId)`, `mark(caseId, milestoneId)`, and `reverse(caseId, milestoneId)`. All three SHALL wrap service `\RuntimeException` as JSON envelopes; `progress` returns HTTP 500 on failure; `mark` and `reverse` return HTTP 400.

#### Scenario: Progress endpoint

- WHEN `progress(caseId, caseTypeId)` is called
- THEN it SHALL return the service's progress payload as JSON, or HTTP 500 `{error: <message>}` on `\RuntimeException`

#### Scenario: Reverse requires reason

- WHEN `reverse` is called with empty or whitespace-only `reason`
- THEN the controller SHALL return HTTP 400 `{error: 'Reason is required for milestone reversal'}` BEFORE calling the service

#### Scenario: User attribution

- WHEN `mark` or `reverse` is called
- THEN the controller SHALL resolve the user via `IUserSession::getUser()?->getUID()`, falling back to `'system'` for anonymous calls

### REQ-002: Milestone-progress aggregation by case type

The system SHALL fetch ordered milestone definitions for the case type, fetch all milestone records for the case, and return a payload of `{milestones: [...], reached: <int>, total: <int>, percentage: <int 0-100>}` where each milestone item carries `{identifier, label, order, description, reached: bool, reachedAt: ?datetime, reachedBy: ?userId}`.

#### Scenario: Empty milestone set

- WHEN the case type has no milestone definitions
- THEN the service SHALL return `{milestones: [], reached: 0, total: 0, percentage: 0}` and NOT attempt to fetch records

#### Scenario: Percentage rounding

- WHEN `total > 0`
- THEN `percentage` SHALL be `(int) round((reached / total) * 100)`

#### Scenario: Definition fields fallback

- WHEN a definition lacks `label`
- THEN the payload SHALL fall back to `name`, then empty string

### REQ-003: Mark milestone with trigger origin (manual/workflow/auto)

The system SHALL persist a milestone-record object with `{case, milestoneDefinition, reachedAt, reachedBy, trigger}` where `trigger` defaults to `'manual'` and accepts arbitrary origin strings (the controller currently always passes `'manual'`; service callers can pass `'workflow'` or `'auto'`).

#### Scenario: Persistence shape

- WHEN `markMilestone(caseId, milestoneDefinitionId, userId, trigger)` is called
- THEN the service SHALL persist `{case, milestoneDefinition, reachedAt: now, reachedBy: userId, trigger}` via OpenRegister and return `{id: <uuid>, reachedAt, reachedBy}`

#### Scenario: Schema-unconfigured guard

- WHEN OpenRegister is unavailable
- THEN the service SHALL throw `\RuntimeException('OpenRegister is not available')`
- AND when the milestone-record schema is unconfigured it SHALL throw `\RuntimeException('Milestone record schema not configured')`

### REQ-004: Reverse milestone with mandatory reason

The system SHALL allow reversing a previously-marked milestone by deleting every matching milestone-record (`case` + `milestoneDefinition`) and logging the reversal with the user id and reason. The controller layer SHALL guarantee `reason` is non-empty (REQ-001); the service SHALL accept it as a parameter for the audit log.

#### Scenario: No matching record

- WHEN no milestone records match `(case, milestoneDefinition)`
- THEN `reverseMilestone` SHALL return `false` without touching OpenRegister or logging a reversal

#### Scenario: Successful reversal

- WHEN one or more matching records exist
- THEN the service SHALL delete each, log `'Milestone reversed: <defId> on case <caseId> by <userId> reason: <reason>'`, and return `true`

#### Notes

- The audit reason is captured only in the log line; there is no per-reversal audit object persisted. Future hardening could persist a `MilestoneReversal` record alongside deletion.

### REQ-005: Duration analytics placeholder for case-type aggregations

The system SHALL expose a `getDurationAnalytics(caseTypeId)` method that, in the current implementation, returns a placeholder shape `{caseTypeId, phases: [], message: 'Duration analytics requires sufficient historical data'}` and emits a `debug`-level log entry.

#### Scenario: Placeholder shape

- WHEN `getDurationAnalytics(caseTypeId)` is called
- THEN the service SHALL log at debug and return the placeholder payload above

#### Notes

- The real aggregation is observed-but-stubbed (`// Placeholder: in production, this would aggregate milestone records across all cases of this type and calculate averages`). The signature is locked so future implementations don't break callers.

### Requirement: Milestone sets MUST be configurable per zaaktype
The system SHALL support configurable milestone sets per zaaktype, where each case type defines its own ordered set of milestones with labels, descriptions, and optional automatic triggers.

#### Scenario: Define milestones for a zaaktype
- **GIVEN** zaaktype `omgevingsvergunning` is being configured in Settings > Case Types
- **WHEN** an admin defines milestones
- **THEN** the following milestone set MUST be storable as an ordered array on the caseType object:
  1. `aanvraag_ontvangen` -- "Aanvraag ontvangen"
  2. `documenten_compleet` -- "Documenten compleet"
  3. `inhoudelijke_beoordeling` -- "Inhoudelijke beoordeling gestart"
  4. `advies_ontvangen` -- "Adviezen ontvangen"
  5. `besluit_genomen` -- "Besluit genomen"
  6. `beschikking_verzonden` -- "Beschikking verzonden"
- **AND** each milestone MUST have: `identifier` (slug), `label` (Dutch display name), `order` (sequence number), optional `description`, and optional `triggerEvent` (n8n webhook event name)

#### Scenario: Different zaaktypes have different milestones
- **GIVEN** zaaktype `melding_openbare_ruimte` has 3 milestones and `omgevingsvergunning` has 6
- **WHEN** viewing cases of each type
- **THEN** each case MUST show progress against its own zaaktype's milestone set
- **AND** the progress indicator MUST adapt its width and step count accordingly

#### Scenario: Milestones can be mapped to status types
- **GIVEN** zaaktype `omgevingsvergunning` has both status types and milestones
- **WHEN** an admin configures milestone `documenten_compleet`
- **THEN** the admin MUST be able to optionally map it to status type `volledigheid_getoetst`
- **AND** when a case reaches that status, the milestone MUST be automatically marked as reached

#### Scenario: Milestones can exist independently of status types
- **GIVEN** milestone `advies_ontvangen` has no status type mapping
- **WHEN** the admin saves the milestone configuration
- **THEN** the milestone MUST be valid without a status mapping
- **AND** it MUST be triggerable only via manual marking or n8n workflow event

#### Scenario: Admin reorders milestones
- **GIVEN** zaaktype `omgevingsvergunning` has 6 milestones
- **WHEN** an admin drags milestone 4 to position 2
- **THEN** the order numbers MUST be recalculated for all milestones
- **AND** existing cases with milestones already reached MUST NOT be affected (historical data preserved)

### Requirement: Milestones MUST be reached automatically or manually with audit trail
The system SHALL support reaching milestones automatically or manually with audit trail; milestones can be triggered by n8n workflow events, status transitions, or marked manually by case workers.

#### Scenario: Automatic milestone from n8n workflow event
- **GIVEN** milestone `documenten_compleet` has `triggerEvent` set to `all_documents_received`
- **WHEN** the n8n workflow sends a webhook to `/api/cases/{zaak-1}/milestones/trigger` with event `all_documents_received`
- **THEN** milestone `documenten_compleet` MUST be marked as reached
- **AND** the timestamp of the event MUST be recorded
- **AND** the trigger source MUST be recorded as "workflow" with the n8n execution ID

#### Scenario: Automatic milestone from status transition
- **GIVEN** milestone `besluit_genomen` is mapped to status type `besluit`
- **WHEN** a case worker changes case `zaak-1` to status `besluit` via the QuickStatusDropdown
- **THEN** milestone `besluit_genomen` MUST be automatically marked as reached
- **AND** the trigger source MUST be recorded as "status_transition" with the status record ID

#### Scenario: Manual milestone marking with reason
- **GIVEN** milestone `advies_ontvangen` has no automatic trigger configured
- **WHEN** a case worker manually marks the milestone as reached on case `zaak-1`
- **THEN** the milestone MUST be recorded with: the case worker's user ID, current timestamp, and an optional reason text
- **AND** the trigger source MUST be recorded as "manual"

#### Scenario: Milestone reversal requires justification
- **GIVEN** milestone 3 of 6 is reached for case `zaak-1`
- **WHEN** a case worker with coordinator role attempts to unmark milestone 3
- **THEN** the system MUST require a mandatory reason text for the reversal
- **AND** the reversal MUST be recorded in the audit trail with: user, timestamp, original reached date, and reason
- **AND** the milestone's `reached` flag MUST be set to false and `reversedAt` timestamp recorded

#### Scenario: Non-coordinator cannot reverse milestones
- **GIVEN** a case worker with behandelaar role
- **WHEN** they attempt to reverse a reached milestone
- **THEN** the system MUST deny the action with message "Alleen een coordinator kan mijlpalen terugdraaien"

### Requirement: Cases MUST display visual milestone progress indicators
The system SHALL display visual milestone progress indicators, showing milestone progress as a step indicator in both list and detail views.

#### Scenario: Progress indicator in case list view
- **GIVEN** 3 cases exist: one at milestone 2/6, one at 4/6, one at 6/6
- **WHEN** viewing the case list (CaseList.vue)
- **THEN** each case row MUST show a compact progress indicator (e.g., "2/6 Documenten compleet")
- **AND** completed cases (6/6) MUST show a green checkmark icon
- **AND** the progress indicator MUST use NL Design System progress bar tokens

#### Scenario: Step indicator in case detail view
- **GIVEN** case `zaak-1` has milestone 3 of 6 reached
- **WHEN** viewing the case detail (CaseDetail.vue)
- **THEN** a horizontal step indicator MUST show all 6 milestones below the status card
- **AND** milestones 1-3 MUST be marked as reached with green dots and timestamps on hover
- **AND** milestones 4-6 MUST be shown as pending with grey dots
- **AND** the current milestone (3) MUST be visually highlighted with a larger dot or accent color

#### Scenario: Step indicator is accessible
- **GIVEN** the milestone step indicator is rendered
- **THEN** it MUST have `role="progressbar"` with `aria-valuenow`, `aria-valuemin`, and `aria-valuemax`
- **AND** each milestone dot MUST be keyboard-focusable with `aria-label` describing the milestone name and status
- **AND** color MUST NOT be the only indicator of milestone state (use icons + text)

#### Scenario: Milestone detail panel shows full history
- **GIVEN** a case worker clicks on a reached milestone dot
- **THEN** a tooltip or panel MUST show: milestone label, description, reached date/time, trigger source (manual/workflow/status), and who triggered it
- **AND** for reversed milestones, the reversal history MUST also be shown

#### Scenario: StatusTimeline and milestone indicator coexist
- **GIVEN** a case has both status types and milestones configured
- **WHEN** viewing the case detail
- **THEN** the StatusTimeline component MUST remain visible (showing status progression)
- **AND** the milestone indicator MUST appear as a separate section labeled "Voortgang"
- **AND** both MUST be independently scrollable if they have many items

### Requirement: Milestone timestamps MUST enable duration analysis
The system SHALL track milestone timestamps to enable duration analysis, as time between milestones is tracked for performance reporting and bottleneck detection.

#### Scenario: Calculate time per phase
- **GIVEN** case `zaak-1` reached milestone 1 on March 1, milestone 2 on March 5, and milestone 3 on March 15
- **WHEN** a manager views the case detail's milestone section
- **THEN** the system MUST show duration between consecutive milestones:
  - Phase 1 to 2 (document collection): 4 days
  - Phase 2 to 3 (assessment start): 10 days
  - Total elapsed: 14 days

#### Scenario: Average milestone duration per zaaktype on dashboard
- **GIVEN** 50 completed `omgevingsvergunning` cases exist
- **WHEN** a manager views the milestone analytics on the Dashboard (Dashboard.vue)
- **THEN** the system MUST show a table with average time between each milestone pair across all completed cases
- **AND** milestones where the average exceeds the configured expected duration MUST be highlighted in red
- **AND** a trend indicator (arrow up/down) MUST show whether performance is improving or degrading compared to the previous period

#### Scenario: Bottleneck detection alert
- **GIVEN** the average time between milestones 2 and 3 for `omgevingsvergunning` is 8 days
- **AND** 5 active cases have been stuck between milestones 2 and 3 for more than 15 days
- **WHEN** the daily analytics job runs
- **THEN** the system MUST flag these cases as potential bottlenecks
- **AND** notify the coordinator with a summary: "5 zaken wachten langer dan gemiddeld op mijlpaal 'Inhoudelijke beoordeling'"

### Requirement: Milestone deadlines MUST be trackable with warnings
The system SHALL support trackable milestone deadlines with warnings, as milestones can have expected completion dates based on the case's start date and zaaktype configuration.

#### Scenario: Milestone deadline calculation
- **GIVEN** zaaktype `omgevingsvergunning` configures milestone 2 (`documenten_compleet`) with expected duration "5 working days from case start"
- **AND** case `zaak-1` starts on 2026-03-01
- **THEN** milestone 2's expected deadline MUST be calculated as 2026-03-08 (5 working days)
- **AND** the milestone indicator MUST show the expected date for unreached milestones

#### Scenario: Milestone deadline warning
- **GIVEN** milestone 2 of case `zaak-1` has expected deadline 2026-03-08
- **AND** the current date is 2026-03-07 (1 day before deadline)
- **AND** milestone 2 is not yet reached
- **THEN** the milestone dot MUST change to amber color
- **AND** a notification MUST be sent to the assigned case worker

#### Scenario: Overdue milestone escalation
- **GIVEN** milestone 2 of case `zaak-1` has expected deadline 2026-03-08
- **AND** the current date is 2026-03-10 (2 days overdue)
- **AND** milestone 2 is still not reached
- **THEN** the milestone dot MUST change to red color
- **AND** a notification MUST be sent to both the case worker and the coordinator
- **AND** the case MUST appear in the "Verlopen mijlpalen" section of the dashboard

### Requirement: Milestone dependencies MUST be enforceable
The system SHALL support enforceable milestone dependencies, where milestones can define prerequisites that MUST be met before they can be reached.

#### Scenario: Sequential milestone dependency
- **GIVEN** milestone 3 (`inhoudelijke_beoordeling`) requires milestone 2 (`documenten_compleet`) to be reached first
- **WHEN** a case worker or workflow attempts to mark milestone 3 as reached while milestone 2 is pending
- **THEN** the system MUST reject the action with message "Mijlpaal 'Documenten compleet' moet eerst bereikt zijn"

#### Scenario: Parallel milestone dependencies
- **GIVEN** milestone 5 (`besluit_genomen`) requires both milestone 3 (`inhoudelijke_beoordeling`) and milestone 4 (`advies_ontvangen`)
- **WHEN** milestone 3 is reached but milestone 4 is not
- **THEN** milestone 5 MUST NOT be reachable
- **AND** the milestone indicator MUST show milestone 5 as "wacht op: Adviezen ontvangen"

#### Scenario: No dependency configured allows free-form reaching
- **GIVEN** milestone 4 (`advies_ontvangen`) has no dependencies configured
- **WHEN** a case worker marks milestone 4 as reached while milestone 2 is still pending
- **THEN** the system MUST allow the action
- **AND** the milestone indicator MUST show milestones 1 and 4 as reached, with 2 and 3 still pending

### Requirement: Milestone progress MUST be available in the API
The system SHALL make milestone progress available in the API, as external systems (citizen portal, dashboards, ketenpartners) need milestone data via the API.

#### Scenario: API returns full milestone progress for authenticated users
- **GIVEN** case `zaak-1` has milestones configured
- **WHEN** `GET /api/cases/{zaak-1}/milestones` is called by an authenticated case worker
- **THEN** the response MUST include:
  - Array of milestones with `identifier`, `label`, `order`, `reached` (boolean), `reachedAt` (ISO timestamp or null), `triggerSource`, `triggeredBy`
  - `progress`: `{"reached": 3, "total": 6, "percentage": 50}`
  - `durations`: array of phase durations between consecutive reached milestones

#### Scenario: Citizen-friendly progress for portal strips internal details
- **GIVEN** the citizen portal queries milestone data for a citizen's case via a public share token
- **WHEN** `GET /api/public/cases/{token}/milestones` is called
- **THEN** only the milestone labels, order, and reached status MUST be returned
- **AND** internal identifiers, case worker details, trigger sources, and duration analytics MUST be excluded
- **AND** the response MUST include a human-readable `currentStep` field (e.g., "Stap 3 van 6: Inhoudelijke beoordeling")

#### Scenario: ZGW-compatible milestone representation
- **GIVEN** the ZGW Zaken API exposes case status history via `/api/v1/statussen`
- **WHEN** milestones are modeled as enriched status data
- **THEN** each milestone MUST be representable as a ZGW-compatible status with `statustype`, `datumStatusGezet`, and `statustoelichting`
- **AND** the ZrcController MUST include milestone data in the status history response

### Requirement: Milestone data MUST be stored as OpenRegister objects
Milestone instances (reached milestones on a case) MUST be stored as structured objects linked to the case.

#### Scenario: Milestone record schema
- **GIVEN** the OpenRegister schema for milestone records
- **THEN** each milestone record MUST contain: `case` (reference to parent case), `milestoneIdentifier` (slug from caseType config), `reached` (boolean), `reachedAt` (datetime), `triggerSource` (enum: manual/workflow/status_transition), `triggeredBy` (user ID or workflow execution ID), `reversedAt` (datetime, nullable), `reversalReason` (string, nullable)
- **AND** the schema MUST be registered as `milestone_record_schema` in `SettingsService::SLUG_TO_CONFIG_KEY`

#### Scenario: Milestone records are created on milestone reach
- **GIVEN** milestone `documenten_compleet` is reached on case `zaak-1`
- **WHEN** the milestone is marked as reached
- **THEN** a new milestone record object MUST be created in the case's register via OpenRegister ObjectService
- **AND** the object MUST reference the case via the `case` field

#### Scenario: Milestone records support audit trail
- **GIVEN** a milestone record for `documenten_compleet` on case `zaak-1`
- **WHEN** the record is queried with audit trail enabled
- **THEN** the OpenRegister audit trail plugin MUST show: creation event, any updates (reversals), and the full change history

### Requirement: Dashboard MUST show milestone-based KPIs
The Procest dashboard MUST include milestone-based performance indicators.

#### Scenario: Milestone completion rate KPI card
- **GIVEN** the Dashboard.vue already shows KPI cards
- **WHEN** a coordinator views the dashboard
- **THEN** a "Mijlpaalvoortgang" KPI card MUST show: number of cases on track (milestone deadlines met), number of cases with overdue milestones, and overall on-time percentage

#### Scenario: Milestone funnel visualization
- **GIVEN** 100 active `omgevingsvergunning` cases
- **WHEN** a manager views the milestone analytics panel
- **THEN** a funnel chart MUST show how many cases are at each milestone stage (e.g., 30 at milestone 1, 25 at milestone 2, etc.)
- **AND** the funnel MUST visually indicate where cases are clustering (potential bottlenecks)

#### Scenario: Filter dashboard by milestone
- **GIVEN** the case list view
- **WHEN** a case worker selects filter "Mijlpaal: Documenten compleet (bereikt)"
- **THEN** only cases that have reached milestone `documenten_compleet` MUST be shown
- **AND** a complementary filter "Mijlpaal: Documenten compleet (niet bereikt)" MUST also be available

