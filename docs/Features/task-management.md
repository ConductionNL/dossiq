# Task Management

The Tasks view provides a list of all tasks in the system, functioning similarly to the Cases view.

![Task Management](/screenshots/task-management.png)

## Overview

The tasks list supports the same two view modes as cases:

- **Table view** (default) -- Sortable column-based table.
- **Cards view** -- Visual card layout.

## Actions

- **Add Item** -- Creates a new task.
- **Actions** -- Bulk actions menu for selected tasks.

## Current State

You do not configure a task schema. Tasks are kept by OpenRegister's task engine, not as register objects. The Tasks page works as soon as OpenRegister is enabled.

## Planned Features

Based on the spec, task management will include:

- Task creation with title, description, assignee, and due date.
- Task status workflow (open, in progress, completed, cancelled).
- Association with parent cases.
- Priority levels.
- Task assignment and reassignment.
- Due date tracking with overdue indicators.
- Bulk status updates.
