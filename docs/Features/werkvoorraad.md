# Werkvoorraad (Work Queue)

The werkvoorraad (work queue) feature provides a team-level view of unassigned and pending work items that need to be picked up by case workers.

## Overview

Unlike "My Work" which shows items assigned to the current user, the werkvoorraad shows the shared pool of work that is available for the team or organizational unit.

## Planned Features

- **Unassigned cases** -- Cases that have not yet been assigned to a specific handler.
- **Team queue** -- Cases assigned to a team or organizational unit rather than an individual.
- **Priority sorting** -- Items sorted by urgency, deadline proximity, or priority level.
- **Claim functionality** -- Allows a case worker to claim a case from the queue, assigning it to themselves.
- **Filter by case type** -- Filter the queue by specific case types (e.g., Bezwaar, Vergunning, Melding).
- **Filter by deadline** -- View items approaching their processing deadline.
- **Bulk assignment** -- Assign multiple items from the queue to a specific handler.

## Relationship to My Work

The werkvoorraad and My Work views are complementary:
- **Werkvoorraad**: "What work is available for the team?"
- **My Work**: "What work is assigned to me?"

Case workers typically check the werkvoorraad to pick up new work, which then appears in their My Work view.

## Status

This feature is defined in the spec at `openspec/specs/werkvoorraad/spec.md` and is planned for future implementation.
