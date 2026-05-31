# Task Management Implementation

## Summary
Implement remaining MVP gaps in task-management spec: task list filters and search, task validation utility, lifecycle transition error messages, and case reference validation on task creation.

## Scope
- REQ-TASK-001: Add case reference validation to task creation
- REQ-TASK-002: Add lifecycle transition error messages to TaskDetail
- REQ-TASK-004: Add filters (status, assignee) and search to TaskList
- REQ-TASK-005: Improve overdue highlighting in task list
- REQ-TASK-006: Better task card anatomy with case reference display
- REQ-TASK-008: Auto-set completedDate on completion (verify)

## Approach
- Create taskValidation.js for form validation
- Enhance TaskList.vue with filter bar and search
- Enhance TaskDetail.vue with transition error messages
