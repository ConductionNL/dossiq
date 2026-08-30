# Tasks: Task Management

## Task 1: Task CRUD within cases [MVP] [DONE]
- **spec_ref**: task-management/spec.md
- **files**: `src/views/tasks/TaskCreateDialog.vue`, `src/views/tasks/TaskList.vue`, `src/views/tasks/TaskDetail.vue`
- **acceptance**: Create, view, edit, delete tasks linked to cases

## Task 2: Task lifecycle (available/active/completed) [MVP] [DONE]
- **spec_ref**: task-management/spec.md
- **files**: `src/utils/taskLifecycle.js`
- **acceptance**: CMMN task state transitions working

## Task 3: Task assignment and due dates [MVP] [DONE]
- **spec_ref**: task-management/spec.md
- **acceptance**: Tasks assignable to Nextcloud users with due dates

## Task 4: Dashboard widget [MVP] [DONE]
- **spec_ref**: task-management/spec.md
- **files**: `src/views/widgets/MyTasksWidget.vue`
- **acceptance**: My Tasks dashboard widget shows assigned tasks

## Task 5: Unit tests (ADR-009) [DONE]
- **spec_ref**: ADR-009
- **acceptance**: Task management tests pass

## Task 6: Documentation and screenshots (ADR-010) [DONE]
- **spec_ref**: ADR-010
- **files**: `docs/features/task-management.md`
- **acceptance**: Task management documented

## Task 7: i18n support (ADR-005) [DONE]
- **spec_ref**: ADR-005
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance**: Task strings in English and Dutch
