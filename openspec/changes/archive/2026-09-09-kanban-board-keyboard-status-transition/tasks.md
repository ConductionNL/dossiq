## 1. Keyboard-operable status move control

- [ ] 1.1 In `src/views/workflow-board/CaseCard.vue`, add a `NcActions`/`NcActionButton` "Move to…"
      menu listing the other status columns (pass `:columns` or `:statusOptions` as a prop from
      `WorkflowBoard.vue` via `BoardColumn.vue`), reachable by Tab and operable with Enter/Space —
      do not nest it inside the card's own `role="button"`/`@click` region in a way that traps
      focus or double-fires the "open case" handler (stop propagation on the menu trigger).
- [ ] 1.2 Emit a `move` event (`caseId`, `newStatusId`) from `CaseCard.vue` when a menu item is
      chosen.
- [ ] 1.3 In `BoardColumn.vue`, re-emit `move` up to `WorkflowBoard.vue` alongside the existing
      `drop`/`dragstart`/`click-case` events.
- [ ] 1.4 In `WorkflowBoard.vue`, wire the new `@move="onDrop"` handler to the **same**
      `onDrop(caseId, newStatusId)` method (line 164) already used by the drag-and-drop path, so
      optimistic update / `saveObject('case', …)` / revert-on-failure behaviour is identical for
      both interaction methods.
- [ ] 1.5 Pass the list of available status columns into `CaseCard.vue` (via `BoardColumn.vue`) so
      the menu can exclude the card's current column and label each option with the target
      status's display name.

## 2. Preserve existing behaviour

- [ ] 2.1 Confirm `@click`/`@keydown.enter`/`@keydown.space` on the card body still navigate to
      the case detail (`goToCase`) unchanged — the new menu button is an additional, separate
      focusable control, not a replacement.
- [ ] 2.2 Confirm `draggable="true"`, `@dragstart`, `@dragover.prevent`, `@drop` are unmodified for
      mouse/touch users.

## 3. Spec + traceability

- [ ] 3.1 Add the MODIFIED requirement to
      `openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md` (this
      change) and run `openspec validate kanban-board-keyboard-status-transition --strict`.
- [ ] 3.2 Add `@spec openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md`
      to the touched Vue components (file-level docblock/comment, matching this app's convention).
- [ ] 3.3 Fix any pre-existing ESLint/stylelint warnings encountered in the touched files while
      implementing this change (project convention — do not defer).

## 4. Test coverage

- [ ] 4.1 Add a Playwright e2e spec (`tests/e2e/spec-coverage/` or `tests/e2e/workflows/`) that
      opens the workflow board, tabs to a card's "Move to…" control with the keyboard, activates
      it with Enter, and asserts the case moves column — without ever dispatching a drag/mouse
      event. Tag it `@e2e openspec/specs/dashboard/spec.md#<anchor>` per this app's gate-19
      convention.
- [ ] 4.2 Add/extend a vitest unit spec for `CaseCard.vue` asserting the `move` event payload
      shape (`caseId`, `newStatusId`) when a menu item is activated via keyboard.

## 5. Verification

- [ ] 5.1 Live-verify: with only a keyboard (no mouse), open the Workflow Board, move a case from
      one status column to another using the new control, and confirm the status persists
      (reload the page and see it in the new column).
- [ ] 5.2 Live-verify: the drag-and-drop path still works unchanged for mouse users.
