---
status: implemented
---
# milestone-tracking Specification

## Purpose
Provide business-friendly progress indicators on cases by abstracting technical process states into milestones that case workers, managers, and citizens can understand. Milestones represent meaningful checkpoints in a case's journey (e.g., "Documents received", "Assessment complete", "Decision made") and are mapped to underlying workflow steps. Visual progress bars show how far along a case is.

Milestone tracking is an established pattern in case management platforms. CMMN 1.1 defines Milestone as a first-class PlanItem type representing a significant event in the case lifecycle. Flowable implements CMMN milestones with reached/not-reached status and timestamps, using sentries (entry criteria) to trigger milestones automatically. The core problem is that technical workflow states (e.g., `UserTask_0x3f2a`) are meaningless to end users. Milestones translate process progress into language that everyone understands.

## Context
The existing `StatusTimeline.vue` component already provides a visual progress indicator showing passed/current/future status dots with dates. Status types are ordered and have timestamps when reached (via `statusRecord` schema). This spec extends the status system with a dedicated milestone layer that can be independent of or mapped to status transitions, providing richer progress tracking for both internal users and external stakeholders (citizens, ketenpartners).

## Requirements

### Requirement: Milestone sets MUST be configurable per zaaktype
The system SHALL support configurable milestone sets per zaaktype, where each case type defines its own ordered set of milestones with labels, descriptions, and optional automatic triggers.

#### Scenario: Define milestones for a zaaktype
- GIVEN zaaktype `omgevingsvergunning` is being configured in Settings > Case Types
- WHEN an admin defines milestones
- THEN the following milestone set MUST be storable as an ordered array on the caseType object:
  1. `aanvraag_ontvangen` -- "Aanvraag ontvangen"
  2. `documenten_compleet` -- "Documenten compleet"
  3. `inhoudelijke_beoordeling` -- "Inhoudelijke beoordeling gestart"
  4. `advies_ontvangen` -- "Adviezen ontvangen"
  5. `besluit_genomen` -- "Besluit genomen"
  6. `beschikking_verzonden` -- "Beschikking verzonden"
- AND each milestone MUST have: `identifier` (slug), `label` (Dutch display name), `order` (sequence number), optional `description`, and optional `triggerEvent` (n8n webhook event name)

#### Scenario: Different zaaktypes have different milestones
- GIVEN zaaktype `melding_openbare_ruimte` has 3 milestones and `omgevingsvergunning` has 6
- WHEN viewing cases of each type
- THEN each case MUST show progress against its own zaaktype's milestone set
- AND the progress indicator MUST adapt its width and step count accordingly

#### Scenario: Milestones can be mapped to status types
- GIVEN zaaktype `omgevingsvergunning` has both status types and milestones
- WHEN an admin configures milestone `documenten_compleet`
- THEN the admin MUST be able to optionally map it to status type `volledigheid_getoetst`
- AND when a case reaches that status, the milestone MUST be automatically marked as reached

#### Scenario: Milestones can exist independently of status types
- GIVEN milestone `advies_ontvangen` has no status type mapping
- WHEN the admin saves the milestone configuration
- THEN the milestone MUST be valid without a status mapping
- AND it MUST be triggerable only via manual marking or n8n workflow event

#### Scenario: Admin reorders milestones
- GIVEN zaaktype `omgevingsvergunning` has 6 milestones
- WHEN an admin drags milestone 4 to position 2
- THEN the order numbers MUST be recalculated for all milestones
- AND existing cases with milestones already reached MUST NOT be affected (historical data preserved)

### Requirement: Milestones MUST be reached automatically or manually with audit trail
The system SHALL support reaching milestones automatically or manually with audit trail; milestones can be triggered by n8n workflow events, status transitions, or marked manually by case workers.

#### Scenario: Automatic milestone from n8n workflow event
- GIVEN milestone `documenten_compleet` has `triggerEvent` set to `all_documents_received`
- WHEN the n8n workflow sends a webhook to `/api/cases/{zaak-1}/milestones/trigger` with event `all_documents_received`
- THEN milestone `documenten_compleet` MUST be marked as reached
- AND the timestamp of the event MUST be recorded
- AND the trigger source MUST be recorded as "workflow" with the n8n execution ID

#### Scenario: Automatic milestone from status transition
- GIVEN milestone `besluit_genomen` is mapped to status type `besluit`
- WHEN a case worker changes case `zaak-1` to status `besluit` via the QuickStatusDropdown
- THEN milestone `besluit_genomen` MUST be automatically marked as reached
- AND the trigger source MUST be recorded as "status_transition" with the status record ID

#### Scenario: Manual milestone marking with reason
- GIVEN milestone `advies_ontvangen` has no automatic trigger configured
- WHEN a case worker manually marks the milestone as reached on case `zaak-1`
- THEN the milestone MUST be recorded with: the case worker's user ID, current timestamp, and an optional reason text
- AND the trigger source MUST be recorded as "manual"

#### Scenario: Milestone reversal requires justification
- GIVEN milestone 3 of 6 is reached for case `zaak-1`
- WHEN a case worker with coordinator role attempts to unmark milestone 3
- THEN the system MUST require a mandatory reason text for the reversal
- AND the reversal MUST be recorded in the audit trail with: user, timestamp, original reached date, and reason
- AND the milestone's `reached` flag MUST be set to false and `reversedAt` timestamp recorded

#### Scenario: Non-coordinator cannot reverse milestones
- GIVEN a case worker with behandelaar role
- WHEN they attempt to reverse a reached milestone
- THEN the system MUST deny the action with message "Alleen een coordinator kan mijlpalen terugdraaien"

### Requirement: Cases MUST display visual milestone progress indicators
The system SHALL display visual milestone progress indicators, showing milestone progress as a step indicator in both list and detail views.

#### Scenario: Progress indicator in case list view
- GIVEN 3 cases exist: one at milestone 2/6, one at 4/6, one at 6/6
- WHEN viewing the case list (CaseList.vue)
- THEN each case row MUST show a compact progress indicator (e.g., "2/6 Documenten compleet")
- AND completed cases (6/6) MUST show a green checkmark icon
- AND the progress indicator MUST use NL Design System progress bar tokens

#### Scenario: Step indicator in case detail view
- GIVEN case `zaak-1` has milestone 3 of 6 reached
- WHEN viewing the case detail (CaseDetail.vue)
- THEN a horizontal step indicator MUST show all 6 milestones below the status card
- AND milestones 1-3 MUST be marked as reached with green dots and timestamps on hover
- AND milestones 4-6 MUST be shown as pending with grey dots
- AND the current milestone (3) MUST be visually highlighted with a larger dot or accent color

#### Scenario: Step indicator is accessible
- GIVEN the milestone step indicator is rendered
- THEN it MUST have `role="progressbar"` with `aria-valuenow`, `aria-valuemin`, and `aria-valuemax`
- AND each milestone dot MUST be keyboard-focusable with `aria-label` describing the milestone name and status
- AND color MUST NOT be the only indicator of milestone state (use icons + text)

#### Scenario: Milestone detail panel shows full history
- GIVEN a case worker clicks on a reached milestone dot
- THEN a tooltip or panel MUST show: milestone label, description, reached date/time, trigger source (manual/workflow/status), and who triggered it
- AND for reversed milestones, the reversal history MUST also be shown

#### Scenario: StatusTimeline and milestone indicator coexist
- GIVEN a case has both status types and milestones configured
- WHEN viewing the case detail
- THEN the StatusTimeline component MUST remain visible (showing status progression)
- AND the milestone indicator MUST appear as a separate section labeled "Voortgang"
- AND both MUST be independently scrollable if they have many items

### Requirement: Milestone timestamps MUST enable duration analysis
The system SHALL track milestone timestamps to enable duration analysis, as time between milestones is tracked for performance reporting and bottleneck detection.

#### Scenario: Calculate time per phase
- GIVEN case `zaak-1` reached milestone 1 on March 1, milestone 2 on March 5, and milestone 3 on March 15
- WHEN a manager views the case detail's milestone section
- THEN the system MUST show duration between consecutive milestones:
  - Phase 1 to 2 (document collection): 4 days
  - Phase 2 to 3 (assessment start): 10 days
  - Total elapsed: 14 days

#### Scenario: Average milestone duration per zaaktype on dashboard
- GIVEN 50 completed `omgevingsvergunning` cases exist
- WHEN a manager views the milestone analytics on the Dashboard (Dashboard.vue)
- THEN the system MUST show a table with average time between each milestone pair across all completed cases
- AND milestones where the average exceeds the configured expected duration MUST be highlighted in red
- AND a trend indicator (arrow up/down) MUST show whether performance is improving or degrading compared to the previous period

#### Scenario: Bottleneck detection alert
- GIVEN the average time between milestones 2 and 3 for `omgevingsvergunning` is 8 days
- AND 5 active cases have been stuck between milestones 2 and 3 for more than 15 days
- WHEN the daily analytics job runs
- THEN the system MUST flag these cases as potential bottlenecks
- AND notify the coordinator with a summary: "5 zaken wachten langer dan gemiddeld op mijlpaal 'Inhoudelijke beoordeling'"

### Requirement: Milestone deadlines MUST be trackable with warnings
The system SHALL support trackable milestone deadlines with warnings, as milestones can have expected completion dates based on the case's start date and zaaktype configuration.

#### Scenario: Milestone deadline calculation
- GIVEN zaaktype `omgevingsvergunning` configures milestone 2 (`documenten_compleet`) with expected duration "5 working days from case start"
- AND case `zaak-1` starts on 2026-03-01
- THEN milestone 2's expected deadline MUST be calculated as 2026-03-08 (5 working days)
- AND the milestone indicator MUST show the expected date for unreached milestones

#### Scenario: Milestone deadline warning
- GIVEN milestone 2 of case `zaak-1` has expected deadline 2026-03-08
- AND the current date is 2026-03-07 (1 day before deadline)
- AND milestone 2 is not yet reached
- THEN the milestone dot MUST change to amber color
- AND a notification MUST be sent to the assigned case worker

#### Scenario: Overdue milestone escalation
- GIVEN milestone 2 of case `zaak-1` has expected deadline 2026-03-08
- AND the current date is 2026-03-10 (2 days overdue)
- AND milestone 2 is still not reached
- THEN the milestone dot MUST change to red color
- AND a notification MUST be sent to both the case worker and the coordinator
- AND the case MUST appear in the "Verlopen mijlpalen" section of the dashboard

### Requirement: Milestone dependencies MUST be enforceable
The system SHALL support enforceable milestone dependencies, where milestones can define prerequisites that MUST be met before they can be reached.

#### Scenario: Sequential milestone dependency
- GIVEN milestone 3 (`inhoudelijke_beoordeling`) requires milestone 2 (`documenten_compleet`) to be reached first
- WHEN a case worker or workflow attempts to mark milestone 3 as reached while milestone 2 is pending
- THEN the system MUST reject the action with message "Mijlpaal 'Documenten compleet' moet eerst bereikt zijn"

#### Scenario: Parallel milestone dependencies
- GIVEN milestone 5 (`besluit_genomen`) requires both milestone 3 (`inhoudelijke_beoordeling`) and milestone 4 (`advies_ontvangen`)
- WHEN milestone 3 is reached but milestone 4 is not
- THEN milestone 5 MUST NOT be reachable
- AND the milestone indicator MUST show milestone 5 as "wacht op: Adviezen ontvangen"

#### Scenario: No dependency configured allows free-form reaching
- GIVEN milestone 4 (`advies_ontvangen`) has no dependencies configured
- WHEN a case worker marks milestone 4 as reached while milestone 2 is still pending
- THEN the system MUST allow the action
- AND the milestone indicator MUST show milestones 1 and 4 as reached, with 2 and 3 still pending

### Requirement: Milestone progress MUST be available in the API
The system SHALL make milestone progress available in the API, as external systems (citizen portal, dashboards, ketenpartners) need milestone data via the API.

#### Scenario: API returns full milestone progress for authenticated users
- GIVEN case `zaak-1` has milestones configured
- WHEN `GET /api/cases/{zaak-1}/milestones` is called by an authenticated case worker
- THEN the response MUST include:
  - Array of milestones with `identifier`, `label`, `order`, `reached` (boolean), `reachedAt` (ISO timestamp or null), `triggerSource`, `triggeredBy`
  - `progress`: `{"reached": 3, "total": 6, "percentage": 50}`
  - `durations`: array of phase durations between consecutive reached milestones

#### Scenario: Citizen-friendly progress for portal strips internal details
- GIVEN the citizen portal queries milestone data for a citizen's case via a public share token
- WHEN `GET /api/public/cases/{token}/milestones` is called
- THEN only the milestone labels, order, and reached status MUST be returned
- AND internal identifiers, case worker details, trigger sources, and duration analytics MUST be excluded
- AND the response MUST include a human-readable `currentStep` field (e.g., "Stap 3 van 6: Inhoudelijke beoordeling")

#### Scenario: ZGW-compatible milestone representation
- GIVEN the ZGW Zaken API exposes case status history via `/api/v1/statussen`
- WHEN milestones are modeled as enriched status data
- THEN each milestone MUST be representable as a ZGW-compatible status with `statustype`, `datumStatusGezet`, and `statustoelichting`
- AND the ZrcController MUST include milestone data in the status history response

### Requirement: Milestone data MUST be stored as OpenRegister objects
Milestone instances (reached milestones on a case) MUST be stored as structured objects linked to the case.

#### Scenario: Milestone record schema
- GIVEN the OpenRegister schema for milestone records
- THEN each milestone record MUST contain: `case` (reference to parent case), `milestoneIdentifier` (slug from caseType config), `reached` (boolean), `reachedAt` (datetime), `triggerSource` (enum: manual/workflow/status_transition), `triggeredBy` (user ID or workflow execution ID), `reversedAt` (datetime, nullable), `reversalReason` (string, nullable)
- AND the schema MUST be registered as `milestone_record_schema` in `SettingsService::SLUG_TO_CONFIG_KEY`

#### Scenario: Milestone records are created on milestone reach
- GIVEN milestone `documenten_compleet` is reached on case `zaak-1`
- WHEN the milestone is marked as reached
- THEN a new milestone record object MUST be created in the case's register via OpenRegister ObjectService
- AND the object MUST reference the case via the `case` field

#### Scenario: Milestone records support audit trail
- GIVEN a milestone record for `documenten_compleet` on case `zaak-1`
- WHEN the record is queried with audit trail enabled
- THEN the OpenRegister audit trail plugin MUST show: creation event, any updates (reversals), and the full change history

### Requirement: Dashboard MUST show milestone-based KPIs
The Procest dashboard MUST include milestone-based performance indicators.

#### Scenario: Milestone completion rate KPI card
- GIVEN the Dashboard.vue already shows KPI cards
- WHEN a coordinator views the dashboard
- THEN a "Mijlpaalvoortgang" KPI card MUST show: number of cases on track (milestone deadlines met), number of cases with overdue milestones, and overall on-time percentage

#### Scenario: Milestone funnel visualization
- GIVEN 100 active `omgevingsvergunning` cases
- WHEN a manager views the milestone analytics panel
- THEN a funnel chart MUST show how many cases are at each milestone stage (e.g., 30 at milestone 1, 25 at milestone 2, etc.)
- AND the funnel MUST visually indicate where cases are clustering (potential bottlenecks)

#### Scenario: Filter dashboard by milestone
- GIVEN the case list view
- WHEN a case worker selects filter "Mijlpaal: Documenten compleet (bereikt)"
- THEN only cases that have reached milestone `documenten_compleet` MUST be shown
- AND a complementary filter "Mijlpaal: Documenten compleet (niet bereikt)" MUST also be available

## Non-Requirements
- This spec does NOT cover BPMN/CMMN model import or visual process modeling
- This spec does NOT cover milestone-based SLA enforcement with contractual penalties
- This spec does NOT cover milestone notification preferences per user

## Dependencies
- OpenRegister for milestone record storage (new `milestoneRecord` schema)
- Existing `caseType` schema for milestone set configuration
- StatusTimeline.vue as visual reference (milestone indicator is a separate component)
- n8n webhooks for automatic milestone triggering
- Dashboard.vue for KPI integration
- NL Design System progress bar tokens for accessible visualization

---

### Current Implementation Status

**Not yet implemented as a standalone feature.** No milestone-specific schemas, controllers, services, or Vue components exist in the Procest codebase.

**Foundation available / partial overlap:**
- The status timeline (`src/views/cases/components/StatusTimeline.vue`) already provides a visual progress indicator showing passed/current/future status dots with dates. This overlaps significantly with the milestone concept -- status types function as milestones in the current implementation.
- Status types are ordered and have timestamps when reached (via `statusRecord` schema in `SettingsService::SLUG_TO_CONFIG_KEY`).
- The case list view already shows status information per case row (via `QuickStatusDropdown`).
- The `statusRecord` schema tracks status transitions with timestamps, providing the data for duration analysis.
- ZGW status endpoints via `ZrcController` track status history.
- The `DeadlinePanel.vue` component already shows deadline and timing information, which could be extended with milestone-specific deadlines.
- The `caseType` schema supports `processingDeadline` which provides the foundation for milestone deadline calculations.

**Key distinction:** The spec envisions milestones as a separate concept from statuses -- milestones are business-friendly progress markers that can be independent of status transitions. The current status timeline serves a similar but not identical purpose.

### Standards & References

- **CMMN 1.1 (OMG)**: Milestone is a first-class PlanItem type (section 5.4.8) -- a plan item that, when achieved, denotes a significant event in the case. Milestones have entry criteria (sentries) that define when they are reached.
- **Flowable CMMN engine**: Implements CMMN milestones with `MilestoneInstance` entity, `reached` state, and sentry evaluation for automatic triggering.
- **ZGW Zaken API (VNG)**: Status history (`statussen`) provides the foundation for milestone timestamps. Milestones extend this with business-friendly labels and progress calculation.
- **GEMMA**: Voortgangsbewaking (progress monitoring) is a standard zaakgericht werken capability. Milestones formalize the "fase" concept used in GEMMA process descriptions.
- **Schema.org**: `schema:Event` could model individual milestone events; `schema:ProgressStatus` for current milestone state.
- **WCAG 2.1 AA**: Step indicators and progress bars must have ARIA roles (`progressbar`), keyboard navigation, and non-color-dependent state indication.
- **Dimpact ZAC**: Uses Flowable CMMN milestones internally but does not expose milestone progress to end users -- an opportunity for Procest to differentiate.
- **ArkCase**: Uses case status pipeline with discrete states rather than explicit milestones -- Procest's milestone layer adds citizen-facing progress that ArkCase lacks.
