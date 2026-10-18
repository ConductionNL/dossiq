# A case type answers its phases with their tasks

## Why

Board DqZaaktype's Fasen en taken tab draws each phase of a case type with the
tasks it runs (role, lead time) and a box of acts available in every phase. The
tasks live in the active workflow definition's `steps`, a JSON-encoded string
no declarative widget can read or group, and a child type takes its phases from
its parent. So the page cannot assemble this itself.

## What changes

- `GET /api/case-types/{id}/phases` (CaseTypePhasesController, read only,
  `NoAdminRequired` like the blueprint read) answers
  `{ caseTypeId, phases: [{ id, name, order, isFinal, tasks: [{ id, title,
  description, order, required, roleTypeId, candidateGroups, leadTimeDays }] }],
  unplaced: [task], alwaysAvailable: [act], taskCount }`.
- A switched-off task is left out; a task naming a status the type does not
  have is listed under `unplaced` rather than dropped.

## Depends on

The grouped-cards widget that draws it is library batch 10 (from BLD-Dq-D).
