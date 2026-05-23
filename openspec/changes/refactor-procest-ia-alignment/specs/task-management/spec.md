---
name: task-management
status: draft
version: draft
---

# Task Management — IA Placement Delta

This delta only modifies the navigation placement requirements of the
`task-management` spec. All data-model, lifecycle, RBAC, and API requirements
(REQ-TASK-001..REQ-TASK-N) are unchanged and remain canonical in
`openspec/specs/task-management/spec.md`.

## ADDED Requirements

### Requirement: Task list MUST be reached via Mijn werk, not a sibling top-level menu

The global task list view (`/tasks`) MUST be discoverable through the "Mijn
werk" top-level menu rather than as a sibling top-level menu entry. This
matches the proposed IA placement `Mijn werk › Taken` and removes the
duplicate framing where Tasks and My Work compete as separate "what's on my
plate" entries.

#### Scenario: Tasks does not appear as a top-level menu item

- GIVEN a behandelaar opens the Procest app
- WHEN the left navigation renders
- THEN the top-level menu MUST NOT include an entry labelled "Tasks" /
  "Taken" with a top-level icon
- AND the manifest `menu[]` array MUST NOT contain an entry with
  `id: "Tasks"` outside of the `section: "settings"` group

#### Scenario: Task list is reachable from Mijn werk

- GIVEN a behandelaar is on `/my-work`
- WHEN they look for the global task list
- THEN the `MyWork` view MUST surface an explicit affordance (tab, link, or
  button) labelled "Taken" / "Tasks" that navigates to `/tasks`
- AND the affordance MUST be discoverable above the fold

#### Scenario: Existing /tasks deep links continue to resolve

- GIVEN a stored bookmark or external link points to `/tasks`
- WHEN a user opens that URL
- THEN the route MUST still resolve to the existing `Tasks` index page (the
  manifest `pages[]` entry with `id: "Tasks"` is preserved)
- AND no 404 or redirect MUST occur

## REMOVED Requirements

### Requirement: Tasks MUST appear as a top-level navigation entry

**Reason:** Replaced by the new requirement above. The proposed IA places
Tasks under Mijn werk, not as a sibling. The implicit assumption (in the
original spec's `src/views/tasks/TaskList.vue` placement and manifest menu
order 50) that Tasks deserves a top-level slot duplicates the My Work framing
and is no longer aligned with the IA.

**Migration:** The `pages[]` entry for `Tasks` is preserved (route `/tasks`
still works for deep links and for `CaseTasksTab` navigation). Only the
`menu[]` entry is removed.
