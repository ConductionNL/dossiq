## Requirements

### Requirement: Drag-and-Drop Workflow Canvas

The system SHALL provide a visual drag-and-drop editor where administrators can build workflow definitions by placing status nodes and connecting them with transition arrows. The editor runs entirely in the Vue 2.7 frontend and persists changes to OpenRegister via the existing API.

**Feature tier**: V1

#### Scenario: Open workflow editor for a zaaktype

- **WHEN** an administrator navigates to Procest Admin > Zaaktypen > "Omgevingsvergunning" > Workflow tab
- **THEN** the system SHALL display a canvas showing all configured status nodes and their transitions
- **AND** the canvas SHALL render status nodes as draggable boxes with their name and step count
- **AND** transitions SHALL be rendered as directional arrows between nodes

#### Scenario: Add a new status node to the canvas

- **WHEN** an administrator drags a "New Status" element from the palette onto the canvas
- **THEN** a new StatusType SHALL be created on the zaaktype in draft state
- **AND** the node SHALL appear at the drop position on the canvas
- **AND** the administrator SHALL be prompted to enter the status name

#### Scenario: Connect two status nodes with a transition

- **WHEN** an administrator drags from the output port of "Intake" to the input port of "In behandeling"
- **THEN** a new StatusTransition SHALL be created in the workflow template
- **AND** a directional arrow SHALL appear between the two nodes
- **AND** the administrator SHALL be able to click the arrow to configure guards and actions

#### Scenario: Reorder steps within a status node

- **WHEN** an administrator drags step "Checklist invullen" above step "Document uploaden" within the "In behandeling" status node
- **THEN** the `order` property of both steps SHALL be updated to reflect the new sequence

### Requirement: Workflow Editor Validation

The system SHALL validate the workflow definition in real-time as the administrator builds it, providing visual feedback on errors and warnings.

**Feature tier**: V1

#### Scenario: Orphaned status node warning

- **WHEN** a status node has no incoming or outgoing transitions (except the initial status)
- **THEN** the editor SHALL display a warning indicator on that node
- **AND** the tooltip SHALL explain: "Deze status is niet bereikbaar via een overgang"

#### Scenario: Missing final status error

- **WHEN** the workflow has no status marked as `isFinal`
- **THEN** the editor SHALL display an error banner: "Workflow heeft geen eindstatus. Voeg minimaal een eindstatus toe."

#### Scenario: Circular transition warning

- **WHEN** the transitions form a cycle without any exit path to a final status
- **THEN** the editor SHALL display a warning: "Circulaire route gedetecteerd zonder pad naar eindstatus"

### Requirement: Step Configuration Panel

The system SHALL provide a side panel when clicking a step within a status node, allowing configuration of step properties, checklist items, required fields, and automatic actions.

**Feature tier**: V1

#### Scenario: Configure step checklist

- **WHEN** an administrator clicks step "Toets ontvankelijkheid" and opens the checklist tab
- **THEN** the panel SHALL show existing checklist items with add/remove/reorder controls
- **AND** each item SHALL have a label (text) and optional description

#### Scenario: Configure step role assignment

- **WHEN** an administrator sets `assigneeRole` to "Vergunningverlener" on step "Inhoudelijke beoordeling"
- **THEN** only users with role "Vergunningverlener" on the case SHALL see this step in their task list
