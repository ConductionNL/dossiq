# Task Management Specification

## Problem
Tasks represent work items within a case. They follow CMMN 1.1 HumanTask concepts and are semantically typed as `schema:Action`. Tasks can be assigned to Nextcloud users, have due dates and priorities, and follow an independent lifecycle within the parent case. Tasks are the primary mechanism for distributing and tracking work across case handlers, advisors, and other participants.
**Standards**: CMMN 1.1 (HumanTask, PlanItem lifecycle), Schema.org (`Action`, `actionStatus`), BPMN 2.0 (task patterns)
**Primary feature tier**: MVP
**Extended features**: V1 (kanban, checklists, dependencies, templates), Enterprise (automation)
**Competitive context**: Flowable provides the most comprehensive task management with a unified task service across BPMN and CMMN engines, supporting a 5-state lifecycle (created/claimed/in-progress/suspended/completed) with delegation and sub-tasks. Dimpact ZAC uses Flowable-backed tasks with WebSocket-based real-time updates and configurable worklists. xxllnc Zaken implements phase-bound tasks that become read-only when the case progresses past their phase. ArkCase uses Activiti-backed tasks with queue-based routing via Drools rules. Procest takes an OpenRegister-first approach where tasks are JSON objects with CMMN-compliant lifecycle states, avoiding the complexity of an embedded workflow engine.
---

## Proposed Solution
Implement Task Management Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the task-management specification.

## Success Criteria
- Create a task linked to a case
- Create a task with all optional fields
- Update a task's description
- Delete a task
- Validation errors on task creation
