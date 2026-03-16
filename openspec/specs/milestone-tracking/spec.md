# milestone-tracking Specification

## Purpose
Provide business-friendly progress indicators on cases by abstracting technical process states into milestones that case workers, managers, and citizens can understand. Milestones represent meaningful checkpoints in a case's journey (e.g., "Documents received", "Assessment complete", "Decision made") and are mapped to underlying workflow steps. Visual progress bars show how far along a case is.

Milestone tracking is an established pattern in case management platforms, mapping milestones to process flow nodes with reached/not-reached status and timestamps, or using "business identifiers" as human-readable progress markers on case plans. The core problem is that technical workflow states (e.g., `UserTask_0x3f2a`) are meaningless to end users. Milestones translate process progress into language that everyone understands.

## Requirements

### Requirement: Milestone sets MUST be configurable per zaaktype
Each case type defines its own ordered set of milestones.

#### Scenario: Define milestones for a zaaktype
- GIVEN zaaktype `omgevingsvergunning` is being configured
- WHEN an admin defines milestones
- THEN the following milestone set MUST be storable:
  1. `aanvraag_ontvangen` -- "Aanvraag ontvangen"
  2. `documenten_compleet` -- "Documenten compleet"
  3. `inhoudelijke_beoordeling` -- "Inhoudelijke beoordeling gestart"
  4. `advies_ontvangen` -- "Adviezen ontvangen"
  5. `besluit_genomen` -- "Besluit genomen"
  6. `beschikking_verzonden` -- "Beschikking verzonden"
- AND each milestone MUST have: `identifier`, `label` (Dutch display name), `order` (sequence number), and optional `description`

#### Scenario: Different zaaktypes have different milestones
- GIVEN zaaktype `melding_openbare_ruimte` has 3 milestones and `omgevingsvergunning` has 6
- WHEN viewing cases of each type
- THEN each case MUST show progress against its own zaaktype's milestone set

### Requirement: Milestones MUST be reached automatically or manually
Milestones can be triggered by workflow events or marked manually by case workers.

#### Scenario: Automatic milestone from workflow event
- GIVEN milestone `documenten_compleet` is mapped to workflow event `all_documents_received`
- WHEN the n8n workflow triggers `all_documents_received` for case `zaak-1`
- THEN milestone `documenten_compleet` MUST be marked as reached
- AND the timestamp of the event MUST be recorded

#### Scenario: Manual milestone marking
- GIVEN milestone `advies_ontvangen` has no automatic trigger configured
- WHEN a case worker manually marks the milestone as reached
- THEN the milestone MUST be recorded with the case worker's ID and current timestamp

#### Scenario: Milestones cannot go backwards
- GIVEN milestone 3 of 6 is reached for case `zaak-1`
- WHEN a case worker attempts to unmark milestone 3
- THEN the system MUST require a reason for reversal
- AND the reversal MUST be recorded in the audit trail

### Requirement: Cases MUST display visual progress indicators
The UI shows milestone progress as a step indicator or progress bar.

#### Scenario: Progress bar in case list view
- GIVEN 3 cases exist: one at milestone 2/6, one at 4/6, one at 6/6
- WHEN viewing the case list
- THEN each case row MUST show a progress indicator (e.g., "2/6", "4/6", "6/6")
- AND completed cases (6/6) MUST be visually distinct (e.g., green checkmark)

#### Scenario: Step indicator in case detail view
- GIVEN case `zaak-1` has milestone 3 of 6 reached
- WHEN viewing the case detail
- THEN a horizontal step indicator MUST show all 6 milestones
- AND milestones 1-3 MUST be marked as reached (with timestamps on hover)
- AND milestones 4-6 MUST be shown as pending
- AND the current milestone (3) MUST be visually highlighted

### Requirement: Milestone timestamps MUST enable duration analysis
Time between milestones is tracked for performance reporting.

#### Scenario: Calculate time per phase
- GIVEN case `zaak-1` reached milestone 1 on March 1, milestone 2 on March 5, and milestone 3 on March 15
- WHEN a manager views the performance report
- THEN the system MUST show:
  - Phase 1->2 (document collection): 4 days
  - Phase 2->3 (assessment start): 10 days
  - Total elapsed: 14 days

#### Scenario: Average milestone duration per zaaktype
- GIVEN 50 completed `omgevingsvergunning` cases exist
- WHEN a manager views the milestone analytics
- THEN the system MUST show average time between each milestone pair
- AND highlight milestones where cases consistently take longer than expected

### Requirement: Milestone status MUST be available in the API
External systems (citizen portal, dashboards) need milestone data.

#### Scenario: API returns milestone progress
- GIVEN case `zaak-1` has milestones configured
- WHEN `GET /api/cases/{zaak-1}/milestones` is called
- THEN the response MUST include:
  - Array of milestones with `identifier`, `label`, `order`, `reached` (boolean), and `reachedAt` (timestamp or null)
  - `progress`: `{"reached": 3, "total": 6, "percentage": 50}`

#### Scenario: Citizen-friendly progress for portal
- GIVEN the citizen portal queries milestone data for a citizen's case
- THEN only the milestone labels and reached status MUST be returned
- AND internal identifiers and case worker details MUST be excluded
