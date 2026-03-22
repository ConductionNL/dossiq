# Milestone Tracking

The milestone tracking feature monitors key progress points within a case lifecycle, providing visibility into case processing stages and deadlines.

## Overview

Milestones represent significant events or checkpoints in a case's lifecycle. They help case workers and managers track whether a case is progressing according to schedule.

## Planned Features

- **Milestone definitions** -- Define milestones per case type (e.g., "Intake complete", "Assessment done", "Decision published").
- **Automatic milestone triggers** -- Milestones triggered automatically by status changes or other events.
- **Manual milestone recording** -- Allow case workers to manually mark milestones as reached.
- **Deadline tracking** -- Each milestone can have a target date derived from the case start date and processing rules.
- **Overdue alerts** -- Notifications when milestones are not reached by their target date.
- **Visual timeline** -- Display milestones on a visual timeline in the case dashboard view.
- **Reporting** -- Track milestone completion rates and average time-to-milestone across case types.

## Use Cases

- Track that a permit application has been assessed within the legally mandated 8-week period.
- Monitor that a complaint hearing was scheduled within 2 weeks of receipt.
- Verify that all required documents were received before the review deadline.

## Status

This feature is defined in the spec at `openspec/specs/milestone-tracking/spec.md` and is planned for future implementation.
