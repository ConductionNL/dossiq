# Proposal: case-last-activity-at

## Why

The stalled-cases card (DqDashboard, DqMijnTeam "Vastgelopen zaken") sorted by `@self.updated`, and `daysSinceActivity`
counted from it. The nightly rematerialisation and an example-set refresh re-save every case, so no case ever looked
stalled, and only cases nothing re-saved floated up.

## What changes

- The case gets `lastActivityAt` (date-time): when someone last worked on it.
- One listener stamps it on work: a status move, a note added, a document or a contact created on the case, a completed
  task, and a new case that arrives without one. A save that leaves the status alone does not stamp.
- `daysSinceActivity` counts from `lastActivityAt`, falling back to the creation date; every stalled list sorts on it.
