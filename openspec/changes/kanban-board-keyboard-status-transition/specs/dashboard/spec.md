# Dashboard — Workflow Board Keyboard Operability Delta

**Spec refs**: `dashboard` (REQ-DASH-V1-006), ADR-010 (NL Design System / WCAG AA), ADR-004
(frontend — WCAG AA compliance: keyboard-navigable).

## MODIFIED Requirements

### Requirement: REQ-DASH-V1-006 Workflow Board View [V1]

`WorkflowBoard.vue` at `/workflow-board` SHALL provide a Kanban board with one column per
non-final status type, case cards in each column, and a status transition control that MUST be
operable by both drag-and-drop AND keyboard alone — drag-and-drop MUST NOT be the only path to
advance a case's status.

#### Scenario DASH-V1-006a: Board columns reflect status types

- GIVEN status types: Ontvangen (order 1), In behandeling (order 2), Besluitvorming (order 3), each non-final
- WHEN the user views the Workflow Board
- THEN the board MUST display 3 columns in order: Ontvangen, In behandeling, Besluitvorming
- AND each column header MUST show the status name and the count of cases in that status

#### Scenario DASH-V1-006b: Case cards show key information

- GIVEN case "2026-0042 Omgevingsvergunning - Bakkersdijk 12" in status "In behandeling"
- WHEN the user views the Workflow Board
- THEN the case card MUST show: case identifier, title (truncated if necessary), case type badge, assignee name, and deadline with color indicator

#### Scenario DASH-V1-006c: Drag to advance case status

- GIVEN case "2026-0042" is in the "Ontvangen" column
- WHEN the user drags the card to the "In behandeling" column and drops it
- THEN the system MUST update the case's `status` to the "In behandeling" statusType ID
- AND the card MUST move to the "In behandeling" column
- AND if the update fails (e.g., permission denied), the card MUST return to its original column

#### Scenario DASH-V1-006d: Keyboard-only status transition (NEW)

- GIVEN case "2026-0042" is in the "Ontvangen" column and the user is navigating with only a
  keyboard (no mouse/touch)
- WHEN the user tabs to the case card's "Move to…" control and selects "In behandeling" via
  Enter/Space
- THEN the system MUST update the case's `status` to the "In behandeling" statusType ID via the
  same persistence path as the drag-and-drop scenario (optimistic move, `saveObject('case', …)`,
  revert-and-toast on failure)
- AND the card MUST move to the "In behandeling" column
- AND the card's existing "open case detail" keyboard activation (Enter/Space on the card body)
  MUST remain unaffected by the new control

#### Scenario DASH-V1-006e: Drag path unchanged (NEW)

- GIVEN a mouse/touch user
- WHEN they drag a card between columns as in Scenario DASH-V1-006c
- THEN the behaviour MUST be identical to before this change — no regression to the existing drag
  gesture
