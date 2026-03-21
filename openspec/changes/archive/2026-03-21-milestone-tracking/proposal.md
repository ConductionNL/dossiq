# milestone-tracking Specification

## Problem
Provide business-friendly progress indicators on cases by abstracting technical process states into milestones that case workers, managers, and citizens can understand. Milestones represent meaningful checkpoints in a case's journey (e.g., "Documents received", "Assessment complete", "Decision made") and are mapped to underlying workflow steps. Visual progress bars show how far along a case is.
Milestone tracking is an established pattern in case management platforms. CMMN 1.1 defines Milestone as a first-class PlanItem type representing a significant event in the case lifecycle. Flowable implements CMMN milestones with reached/not-reached status and timestamps, using sentries (entry criteria) to trigger milestones automatically. The core problem is that technical workflow states (e.g., `UserTask_0x3f2a`) are meaningless to end users. Milestones translate process progress into language that everyone understands.

## Proposed Solution
Implement milestone-tracking Specification following the detailed specification. Key requirements include:
- Requirement: Milestone sets MUST be configurable per zaaktype
- Requirement: Milestones MUST be reached automatically or manually with audit trail
- Requirement: Cases MUST display visual milestone progress indicators
- Requirement: Milestone timestamps MUST enable duration analysis
- Requirement: Milestone deadlines MUST be trackable with warnings

## Scope
This change covers all requirements defined in the milestone-tracking specification.

## Success Criteria
- Define milestones for a zaaktype
- Different zaaktypes have different milestones
- Milestones can be mapped to status types
- Milestones can exist independently of status types
- Admin reorders milestones
