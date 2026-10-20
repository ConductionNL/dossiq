# case-type-phase-add-task

## Why

Board dossiq/DqZaaktype has "Taak toevoegen" on the phase board. A task is a step of the case type's workflow
definition under the status it runs in, and the steps live in the definition's `steps` JSON string, which only the
workflow canvas wrote. GET `/api/case-types/{id}/phases` was read-only.

## What changes

- `POST /api/case-types/{id}/phases/{phase}/steps` (admin-only) adds a task (title, and optionally description, working
  days and candidate groups) to the phase, as the last task of that phase, in the case type's newest DRAFT workflow
  definition. A published definition is never changed: cases on it keep their version. Answers 201 with the step and
  the draft's id and version; 400 without a title, 404 for a status the type does not have (own or inherited), 409 for a
  type without a draft ("start a new version"), 500 when the draft was not saved.
- The phase board offers "Add task" in every phase (nextcloud-vue `groupAction`, open-form POST).
- Dutch strings.

## Depends on

nextcloud-vue `feat/grouped-cards-group-action` (schema 2.136.0).
