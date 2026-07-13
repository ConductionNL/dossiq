# Visual Workflow Editor — Integration Delta

**Spec refs**: `visual-workflow-editor`, ADR-004 (frontend — WCAG AA
compliance: keyboard-navigable), ADR-022 (single OR ObjectService write
path).

## MODIFIED Requirements

### Requirement: Drag-and-Drop Workflow Canvas

The system SHALL provide a visual drag-and-drop editor where administrators
can build workflow definitions by placing status nodes and connecting them
with transition arrows. The editor runs entirely in the Vue 2.7 frontend
and persists changes to OpenRegister via the existing single ObjectService
write path (no parallel/bespoke save path). The editor is fully integrated
into `CaseTypeDetail.vue`'s "Workflow" tab (`WorkflowTab.vue` ->
`WorkflowEditor.vue`); no second, unwired editor implementation exists in
the codebase.

**Feature tier**: V1

#### Scenario: Open workflow editor for a case type

- **WHEN** an administrator navigates to Procest Admin > Case types >
  "Omgevingsvergunning" > Workflow tab
- **THEN** the system SHALL display a canvas showing all configured status
  nodes and their transitions
- **AND** the canvas SHALL render status nodes as draggable boxes with
  their name and step count
- **AND** transitions SHALL be rendered as directional arrows between nodes

#### Scenario: Add a new status node to the canvas

- **WHEN** an administrator drags a "New Status" element from the palette
  onto the canvas
- **THEN** a new StatusType SHALL be created on the case type in draft
  state
- **AND** the node SHALL appear at the drop position on the canvas
- **AND** the administrator SHALL be prompted to enter the status name

#### Scenario: Connect two status nodes with a transition

- **WHEN** an administrator drags from the output port of "Intake" to the
  input port of "In behandeling"
- **THEN** a new StatusTransition SHALL be created in the workflow template
- **AND** a directional arrow SHALL appear between the two nodes
- **AND** the administrator SHALL be able to click the arrow to configure
  guards and actions

#### Scenario: Delete a status node (NEW)

- **GIVEN** a workflow with at least two status nodes, one of them marked
  `isFinal`
- **WHEN** an administrator deletes a non-final status node via its node
  menu
- **THEN** the StatusType SHALL be deleted through the OpenRegister object
  store
- **AND** any transitions and steps referencing that node SHALL be removed
  from the working copy
- **AND** the canvas SHALL no longer render the deleted node
- **WHEN** an administrator attempts to delete the last remaining
  `isFinal` status node
- **THEN** the deletion SHALL be blocked with a message explaining at
  least one final status must remain

#### Scenario: Reorder steps within a status node

- **WHEN** an administrator drags step "Checklist invullen" above step
  "Document uploaden" within the "In behandeling" status node
- **THEN** the `order` property of both steps SHALL be updated to reflect
  the new sequence

### Requirement: Workflow Editor Validation

The system SHALL validate the workflow definition in real-time as the
administrator builds it, using the same structural rules the backend
publish gate enforces, and SHALL block publishing an invalid definition
with a per-issue message list.

**Feature tier**: V1

#### Scenario: Orphaned status node warning

- **WHEN** a status node has no incoming or outgoing transitions and more
  than one status node exists
- **THEN** the editor SHALL display a warning indicator referencing that
  node's name

#### Scenario: Missing final status error

- **WHEN** the workflow has no status marked as `isFinal`
- **THEN** the editor SHALL display an error banner: "Workflow has no
  final status. Add at least one final status."

#### Scenario: Unreachable final status error (NEW)

- **GIVEN** a status node marked `isFinal`
- **WHEN** no path of transitions from any starting status (a status with
  no incoming transition) reaches that final status
- **THEN** the editor SHALL display an error naming the unreachable final
  status

#### Scenario: Dangling transition error (NEW)

- **WHEN** a transition's `fromStatus` or `toStatus` does not reference a
  status node that belongs to this workflow
- **THEN** the editor SHALL display an error for that transition
- **AND** this is the same referential-integrity rule
  `WorkflowDefinitionService::publish()` enforces server-side, so a
  definition that passes client validation SHALL also pass the backend
  check

#### Scenario: Duplicate transition warning (NEW)

- **WHEN** two transitions share the same `fromStatus`/`toStatus` pair
- **THEN** the editor SHALL display a warning on the duplicate

#### Scenario: Circular transition warning

- **WHEN** the transitions form a cycle without any exit path to a final
  status
- **THEN** the editor SHALL display a warning naming the node(s) on the
  cycle

#### Scenario: Publish blocked on invalid definition (NEW)

- **GIVEN** a draft workflow definition with at least one validation error
- **WHEN** an administrator clicks "Publish"
- **THEN** the system SHALL block the publish call before any network
  request and display every validation error/warning message
- **AND** no draft-to-published transition SHALL occur

### Requirement: Step Configuration Panel

The system SHALL provide a side panel when clicking a step within a status
node, allowing configuration of step properties, checklist items, required
fields, automatic actions, and deletion of the step.

**Feature tier**: V1

#### Scenario: Configure step checklist

- **WHEN** an administrator clicks step "Toets ontvankelijkheid" and opens
  the checklist tab
- **THEN** the panel SHALL show existing checklist items with
  add/remove/reorder controls
- **AND** each item SHALL have a label (text) and optional description

#### Scenario: Configure step role assignment

- **WHEN** an administrator sets `assigneeRole` to "Vergunningverlener" on
  step "Inhoudelijke beoordeling"
- **THEN** only users with role "Vergunningverlener" on the case SHALL see
  this step in their task list

#### Scenario: Delete a step (NEW)

- **WHEN** an administrator clicks "Delete step" in the step configuration
  panel
- **THEN** the step SHALL be removed from the working copy
- **AND** the panel SHALL close
- **AND** the node's step count badge SHALL update

## ADDED Requirements

### Requirement: Publish Uses the Canonical Write Path

Publishing a workflow definition from the canvas SHALL go through the same
backend endpoint (`POST /api/workflow-definitions/{id}/publish` ->
`WorkflowDefinitionService::publish()`) as any other publish trigger in the
app, so referential-integrity checking, role-authorization freezing
(ADR-022), previous-active-version deprecation, and case-type pinning
happen identically regardless of entry point.

**Feature tier**: V1

#### Scenario: Canvas publish enforces referential integrity

- **GIVEN** a draft workflow definition whose transitions were left
  referencing a status node from a different (now-deleted) definition
- **WHEN** the client-side validation somehow misses it and the
  administrator clicks "Publish"
- **THEN** the backend `publish` endpoint SHALL reject the request and the
  editor SHALL surface the failure — the definition SHALL NOT become
  published/active

#### Scenario: Canvas publish freezes role authorization

- **GIVEN** a transition with `routingRule` assigning a role type mapped
  to an NC group
- **WHEN** the definition is published from the canvas
- **THEN** the transition's `authorization` list SHALL be resolved and
  frozen exactly as it is when published from the generic (non-canvas)
  workflow-definitions page

### Requirement: Keyboard-Operable Canvas

The system SHALL make every canvas action reachable by mouse (select a
node, connect two nodes, disconnect a transition, delete a status node,
add a status node) also reachable by keyboard alone, via a parallel
focusable control that invokes the same handler as the pointer gesture —
mirroring the pattern established by
`kanban-board-keyboard-status-transition`.

**Feature tier**: V1

#### Scenario: Keyboard node selection

- **WHEN** a keyboard-only user tabs to a status node and presses Enter or
  Space
- **THEN** the node SHALL be selected exactly as a mouse click would select
  it

#### Scenario: Keyboard connect/disconnect

- **WHEN** a keyboard-only user opens a status node's actions menu and
  chooses "Connect to {other node}" or "Disconnect from {other node}"
- **THEN** the transition SHALL be created or removed via the same store
  action the drag-to-connect / config-panel-delete gestures use

#### Scenario: Keyboard add status node

- **WHEN** a keyboard-only user activates the palette's "Add status node"
  button
- **THEN** a new StatusType SHALL be created identically to the
  drag-and-drop path, placed at a default position

#### Scenario: Visible action names

- **WHEN** any new canvas action control renders
- **THEN** it SHALL expose a visible text name (not an icon-only control
  relying solely on `aria-label`)
