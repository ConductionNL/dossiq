# Proposal: kanban-board-keyboard-status-transition

kind: code — accessibility fix (WCAG 2.1 AA, 2.1.1 Keyboard). Not covered by any active or
archived change; `dashboard`/`REQ-DASH-V1-006` (the spec the board cites) does not mention
keyboard operability.

## Why

`src/views/workflow-board/WorkflowBoard.vue` is a Kanban board where **the only way to change a
case's workflow status is dragging its card from one column to another**:

- The board's own subtitle states this in plain language: `{{ t('dossiq', 'Drag cases between
  statuses to advance their workflow') }}` (`WorkflowBoard.vue` line 16) and its header comment:
  "a Kanban board with ... drag-to-advance status transitions" (line 4-7).
- `CaseCard.vue` (lines 10-20) is `draggable="true"` with a `@dragstart` handler that starts the
  drag. It also has `role="button"`, `tabindex="0"`, and keyboard handlers — but those keyboard
  handlers (`@keydown.enter`, `@keydown.space.prevent`) and the `@click` handler all emit
  `click`, which `WorkflowBoard.vue`'s `goToCase(caseId)` (line 209) resolves to **navigating to
  the case detail page** — not a status change.
- `BoardColumn.vue` only exposes `@dragover.prevent`/`@drop` (lines 15-17) — no button, menu, or
  other keyboard-operable control to move a case into that column.
- The actual status mutation lives in `WorkflowBoard.vue`'s `onDrop(caseId, newStatusId)` (line
  164), which is only ever invoked via the `@drop` event chain from `BoardColumn.vue`
  (`WorkflowBoard.vue` line 51) — there is no code path into `onDrop` that does not originate from
  a mouse/touch drag gesture.

This means a keyboard-only user (motor-impaired citizen-facing case handlers is exactly the
persona ADR-010/NL Design System targets) can view the board but has **no way to advance a case's
status from it** — a hard WCAG 2.1.1 (Keyboard) failure on a primary interaction, not just a
secondary affordance.

## What Changes

- **REQ-KBD-01 (BREAKING: UI)**: `CaseCard.vue` MUST expose a keyboard-operable way to move a case
  to a different status/column — e.g. a "Move to…" menu (`NcActions`/`NcActionButton` per status
  column) reachable via Tab + Enter/Space, that calls the same `onDrop`-equivalent status-change
  path as the drag gesture.
- **REQ-KBD-02**: The existing `@click`/Enter/Space "open case detail" behaviour on the card body
  MUST remain available (it is a legitimate, separate action) — the new move-status control is
  additive, not a replacement of the click-to-open behaviour.
- **REQ-KBD-03**: The drag-and-drop path (`draggable`, `@dragstart`/`@dragover`/`@drop`) MUST be
  preserved unchanged for mouse/touch users.

## Impact

- Affected spec: `dashboard` (`REQ-DASH-V1-006`, workflow board) — MODIFIED to add the keyboard
  operability requirement.
- Affected code: `src/views/workflow-board/CaseCard.vue`, `src/views/workflow-board/BoardColumn.vue`,
  `src/views/workflow-board/WorkflowBoard.vue`.
- BREAKING (UI only): `CaseCard.vue` gains a new visible control (a move/status menu); no route,
  API, or data-shape change.
