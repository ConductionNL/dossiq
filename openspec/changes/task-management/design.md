# Task Management Design

## Status
pr-created

## Overview
Implement task management MVP enhancements to Procest: task list filtering and search, task validation utilities, lifecycle transition error messages, and case reference validation on task creation.

## Requirements
- REQ-TASK-001: Add case reference validation to task creation
- REQ-TASK-002: Add lifecycle transition error messages to TaskDetail
- REQ-TASK-004: Add filters (status, assignee, priority) and search to TaskList
- REQ-TASK-005: Improve overdue highlighting in task list
- REQ-TASK-006: Better task card anatomy with case reference display
- REQ-TASK-008: Auto-set completedDate on completion

## Components
1. **TaskList.vue** - Enhanced with filters (status, assignee, priority) and search
2. **TaskDetail.vue** - Enhanced with lifecycle transition error feedback, auto-set completedDate
3. **TaskCreateDialog.vue** - Enhanced with case field validation
4. **taskValidation.js** - New validation utility for task create/update/transition operations

## Implementation Status
All requirements implemented:
- ✓ Case reference validation in task creation (required field)
- ✓ Lifecycle transition error feedback with descriptive messages
- ✓ Filter bar with status, assignee, priority filters
- ✓ Search functionality on task title and description
- ✓ Overdue highlighting (red badge with left border on list)
- ✓ Task card displays case reference as clickable link
- ✓ completedDate auto-set to ISO timestamp on completion transition
