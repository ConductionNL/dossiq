---
sidebar_position: 4
title: Move a case through its workflow
description: Transition a case to the next status, complete required tasks, and watch the deadline countdown re-base.
---

# Move a case through its workflow

Cases in Procest follow a configurable workflow — each case-type defines its allowed statuses and the transitions between them. This tutorial walks one case from intake to closure.

## Goal

By the end you will have opened a case, transitioned it through at least one status, and confirmed the History tab records the transition.

## Prerequisites

- Completed [Open and read a case](./03-view-case.md).
- A case-type configured with at least two allowed statuses (see [Configure case types and workflows](../admin/01-configure-case-types.md)).
- A case assigned to you (or one you have permission to advance).

## Steps

1. Open the case detail view. The header shows the current status pill — for a fresh case usually *Open* or *In behandeling*.

   ![Case header, current status](/screenshots/tutorials/user/04-advance-case-01.png)

2. Click the status pill (or the **Change status** button on the header bar). A dialog opens with the allowed next statuses for this case-type — only valid transitions appear.

   ![Status transition dialog](/screenshots/tutorials/user/04-advance-case-02.png)

3. Pick the target status, add an optional motivation in the *Reason* field, and click **Confirm**. The dialog closes; the status pill on the header updates immediately.

   ![Confirm status change](/screenshots/tutorials/user/04-advance-case-03.png)

4. Open the **Tasks** tab in the sidebar — automatic actions for the new status may have created fresh tasks (e.g. "Review by manager", "Send confirmation"). Required tasks must be completed before the next transition is offered.

   ![Tasks created by transition](/screenshots/tutorials/user/04-advance-case-04.png)

5. Open the **History** tab — the transition is logged with who, when, from-status, to-status, and the reason. This timeline is the audit trail.

   ![History timeline](/screenshots/tutorials/user/04-advance-case-05.png)

## Verification

You have transitioned correctly when: the header status pill matches the target, the deadline countdown (if the case-type re-bases on status) shows the new due date, and the History tab lists the transition as the latest entry.

## Common issues

| Symptom | Fix |
|---|---|
| Status pill is greyed out / not clickable | You do not hold a role that may transition this case; ask an admin to add you as a handler. |
| Dialog shows "No transitions available" | The case is at a terminal status, or the case-type has no outgoing transitions defined — see [Configure case types and workflows](../admin/01-configure-case-types.md). |
| Required tasks block the next transition | Complete the open Tasks listed in the sidebar, or have a colleague complete tasks assigned to them. |
| Deadline does not re-base | The case-type has no per-status deadline configured; this is configuration not a bug. |

## Reference

- [Case management](../../features/case-management.md) — model and lifecycle.
- [Configure case types and workflows](../admin/01-configure-case-types.md) — defining the allowed transitions.
- [Automatic actions](../admin/02-automatic-actions.md) — task generation rules.
