# Workflow Engine Enhancement

**Issue:** #84
**Branch:** `feature/84/workflow-engine-enhancement`
**PR:** #93

## Overview

Adds a zero-code visual workflow builder that allows administrators to define process steps, status transitions, guards, and automatic actions per zaaktype. Caseworkers see available transitions on the case detail view, filtered by role and guard evaluation.

## Architecture

### Backend

- **Schema:** `workflowTemplate` added to `lib/Settings/dossiq_register.json`
  - Fields: `title`, `description`, `caseTypeId`, `version`, `isActive`, `isDraft`, `steps` (JSON array), `transitions` (JSON array), `guards` (JSON array), `automaticActions` (JSON array)

### Frontend

- **Pinia store:** `src/store/modules/workflow.js`
  - CRUD on workflowTemplate objects via OpenRegister
  - Guard evaluation (4 types: role-check, field-value, date-range, expression)
  - Version management (draft → publish)
  - Import/export as JSON
  - Automatic action dispatch (email via n8n, task creation, webhooks, field update, notification, subcase)

- **Visual Editor:** `src/views/settings/WorkflowEditor.vue`
  - SVG canvas with draggable status nodes
  - Connection ports for transitions
  - Pan/zoom via CSS transform

- **Configuration Panels:**
  - `StepConfigPanel.vue`: checklist editor, guard configuration
  - `TransitionConfigPanel.vue`: action configuration per transition

- **Case Detail Integration:** `WorkflowTransitions.vue`: shows available transitions filtered by role/guards

## Guards

| Type | Description |
|------|-------------|
| `role-check` | Only users with specified role can perform transition |
| `field-value` | Transition available only when a case field has a given value |
| `date-range` | Transition available within a date window |
| `expression` | Custom JS expression evaluated against case data |

## Automatic Actions (per transition)

| Type | Description |
|------|-------------|
| `send-email` | Trigger n8n webhook with email payload |
| `create-task` | Automatically create a task assigned to a role |
| `webhook` | Call an external webhook URL |
| `update-field` | Update a case field value |
| `send-notification` | Send Nextcloud notification |
| `create-subcase` | Create a linked sub-case of a given type |

## Testing

Unit tests are in `tests/Unit/Settings/WorkflowEngineSchemaTest.php`. They verify:
- `dossiq_register.json` is valid JSON and follows OpenAPI structure
- `workflowTemplate` schema is registered with required properties (`steps`, `transitions`)
- All core case management schemas remain present after the migration
