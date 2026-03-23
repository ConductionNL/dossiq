# Task Management

The Tasks view provides a list of all tasks in the system, functioning similarly to the Cases view.

![Task Management](../screenshots/task-management.png)

## Overview

The tasks list supports the same two view modes as cases:

- **Table view** (default) -- Sortable column-based table.
- **Cards view** -- Visual card layout.

## Actions

- **Add Item** -- Creates a new task.
- **Actions** -- Bulk actions menu for selected tasks.

## Current State

The task list view depends on the OpenRegister object type "task" being properly registered. Task schemas must be configured in the Settings > Configuration page.

## Planned Features

Based on the spec, task management will include:

- Task creation with title, description, assignee, and due date.
- Task status workflow (open, in progress, completed, cancelled).
- Association with parent cases.
- Priority levels.
- Task assignment and reassignment.
- Due date tracking with overdue indicators.
- Bulk status updates.
