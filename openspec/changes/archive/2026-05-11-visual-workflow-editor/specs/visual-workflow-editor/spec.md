## ADDED Requirements

### Requirement: Graph Library Foundation
The system SHALL adopt `@vue-flow/core` (1.x) as the canvas rendering and interaction library for the visual workflow editor. The dependency SHALL be declared in `package.json` together with the official `@vue-flow/background` and `@vue-flow/controls` add-ons and the `dagre` auto-layout utility. No other graph library SHALL be used for this surface.

**Feature tier**: V1

#### Scenario: Bundle declares vue-flow as a direct dependency
- **WHEN** a developer inspects `package.json`
- **THEN** `@vue-flow/core`, `@vue-flow/background`, `@vue-flow/controls`, and `dagre` SHALL appear under `dependencies`
- **AND** the lockfile SHALL pin a 1.x major version of `@vue-flow/core`

#### Scenario: Production build succeeds without peer-dependency warnings
- **WHEN** `npm run build` runs in CI
- **THEN** the build SHALL complete with exit code 0
- **AND** no `npm WARN` peer-dependency messages SHALL be emitted for `vue-flow`

### Requirement: Editor Route and Shell
The system SHALL expose a dedicated route at `Admin > Zaaktypen > {type} > Workflow > Visual` rendering `VisualEditor.vue`, which presents a three-pane layout (left palette, centre canvas, right properties panel) for authoring a single `workflowTemplate`.

**Feature tier**: V1

#### Scenario: Route resolves and renders the editor shell
- **WHEN** an administrator navigates to `Admin > Zaaktypen > Omgevingsvergunning > Workflow > Visual`
- **THEN** the system SHALL render `VisualEditor.vue` with three visible panes: palette, canvas, properties
- **AND** the canvas SHALL load the active published `workflowTemplate` for that zaaktype

#### Scenario: Route loads with no published version
- **WHEN** an administrator opens the editor for a zaaktype that has no published workflow template
- **THEN** the editor SHALL open in edit mode on a fresh draft with `version: 1`, `isDraft: true`
- **AND** the canvas SHALL be empty except for an initial status seeded from the zaaktype's first `StatusType`

### Requirement: Node Palette
The system SHALL provide a left-side `PaletteSidebar.vue` listing exactly four draggable node templates — Status, Decision, Parallel, End — that the administrator drags onto the canvas to insert new nodes.

**Feature tier**: V1

#### Scenario: Palette lists the four node types
- **WHEN** the editor renders the palette
- **THEN** the palette SHALL contain exactly four draggable items labelled "Status", "Beslissing", "Parallel", "Eindstatus"
- **AND** each item SHALL display an icon matching its node type

#### Scenario: Drag-drop from palette adds a node
- **WHEN** an administrator drags the "Status" template onto an empty area of the canvas
- **THEN** a new status node SHALL be inserted in the working copy at the drop coordinates
- **AND** the node SHALL be auto-selected so the properties panel opens for it
- **AND** the working copy SHALL gain a corresponding `workflowTemplate.steps[].status` entry

### Requirement: Canvas Rendering
The system SHALL render the working copy of a `workflowTemplate` on a `<VueFlow>` canvas where every status maps to one of the four node component types (`StatusNode`, `DecisionNode`, `ParallelNode`, `EndNode`) and every `statusTransition` maps to a single `TransitionEdge` component.

**Feature tier**: V1

#### Scenario: Template renders as graph
- **WHEN** a workflow template containing three statuses and two transitions loads
- **THEN** the canvas SHALL render three nodes and two edges in the corresponding shapes
- **AND** transition edges SHALL display their `label` and a badge with the number of `guards`

#### Scenario: Connect two nodes by dragging from output to input handle
- **WHEN** an administrator drags from the bottom output handle of "Intake" to the top input handle of "In behandeling"
- **THEN** a new `statusTransition` SHALL be added to the working copy with `fromStatus = Intake`, `toStatus = In behandeling`, empty `guards`, and `label = ""`
- **AND** the new edge SHALL appear immediately on the canvas

#### Scenario: End node cannot have outgoing transitions
- **WHEN** an administrator attempts to drag a connection out of an End node
- **THEN** the canvas SHALL refuse the connection
- **AND** SHALL display the message "Eindstatus heeft geen uitgaande overgangen"

### Requirement: Properties Panel
The system SHALL provide a right-side `PropertiesPanel.vue` that displays editable fields for the currently selected node or edge, or workflow-level metadata when nothing is selected. Editing SHALL write through to the working copy on blur or after a 500 ms debounce.

**Feature tier**: V1

#### Scenario: Status node properties expose step list and routing rule
- **WHEN** a status node is selected on the canvas
- **THEN** the panel SHALL show editable fields `title`, `isFinal`, an ordered list of steps with add / remove / reorder controls, and SHALL slot the existing `RoutingRuleEditor.vue` for the step's `assigneeRole`
- **AND** editing the title and blurring the field SHALL update the working copy synchronously

#### Scenario: Transition edge properties expose label, guards, and allowedRoles
- **WHEN** a transition edge is selected on the canvas
- **THEN** the panel SHALL show editable fields `label` and `guards[]` and SHALL slot `RoutingRuleEditor.vue` for the transition's `allowedRoles`
- **AND** adding a guard SHALL update the working copy and refresh the badge count on the edge

#### Scenario: No selection shows workflow-level metadata
- **WHEN** nothing is selected
- **THEN** the panel SHALL show `title`, `description`, `version`, `isDraft`, and the count of validation issues currently emitted by `WorkflowValidator`

### Requirement: Live Validation
The system SHALL run `WorkflowValidator` whenever the working copy mutates and SHALL surface issues as overlay badges on affected nodes/edges and as entries in a collapsible "Problemen" panel. The "Publiceren" action SHALL be disabled whenever at least one `error`-level issue is present.

**Feature tier**: V1

The validator SHALL emit issues with codes drawn from the following set:

| Code | Level | Detected when |
|------|-------|---------------|
| `ORPHAN_NODE` | warning | A status node has no incoming and no outgoing transitions |
| `NO_FINAL_STATUS` | error | The working copy contains zero nodes with `isFinal: true` |
| `UNREACHABLE_FINAL` | error | At least one final status is not reachable from the initial status |
| `CYCLE_NO_EXIT` | warning | A cycle exists with no path out to any final status |
| `DUPLICATE_TRANSITION` | warning | Two transitions share the same `(fromStatus, toStatus)` pair |
| `MISSING_LABEL` | warning | A transition has no `label` |

#### Scenario: Missing final status blocks publish
- **WHEN** the working copy contains no status with `isFinal: true`
- **THEN** the canvas SHALL display the banner "Workflow heeft geen eindstatus. Voeg minimaal een eindstatus toe."
- **AND** the "Publiceren" button SHALL be disabled
- **AND** the issue SHALL appear in the Problemen panel with code `NO_FINAL_STATUS` and level `error`

#### Scenario: Orphan node receives warning badge
- **WHEN** a status node "Concept" is added but no transitions connect to it
- **THEN** an amber warning badge SHALL appear on the node
- **AND** the tooltip SHALL read "Deze status is niet bereikbaar via een overgang"
- **AND** the publish action SHALL remain enabled (warning, not error)

#### Scenario: Duplicate transition raises warning
- **WHEN** two transitions exist between the same `fromStatus` and `toStatus`
- **THEN** both edges SHALL receive an amber badge
- **AND** the Problemen panel SHALL list a single `DUPLICATE_TRANSITION` entry referencing both edge ids

### Requirement: Save-as-Version Flow
The system SHALL persist edits through the existing `WorkflowDefinitionService` lifecycle: opening a published version flips to edit mode by creating a fresh draft; the draft auto-saves on a 2 s debounce; publishing transitions the draft to `isActive: true` and deprecates the prior version. The editor SHALL NOT introduce any new backend route.

**Feature tier**: V1

#### Scenario: Edit mode creates a new draft from the active version
- **WHEN** an administrator viewing the active published workflow clicks "Bewerken"
- **AND** no draft currently exists for that zaaktype
- **THEN** the editor SHALL call `POST /api/workflow-definition` with the active version's payload plus `isDraft: true` and `version: active.version + 1`
- **AND** subsequent edits SHALL target that new draft

#### Scenario: Auto-save after debounce
- **WHEN** the administrator makes an edit and remains idle for 2 seconds
- **THEN** the editor SHALL call `PUT /api/workflow-definition/{draftId}` with the current working copy
- **AND** the editor SHALL display an "Opgeslagen" indicator next to the save button

#### Scenario: Publish requires no error-level validation issues
- **WHEN** the administrator clicks "Publiceren"
- **AND** at least one error-level issue is emitted by `WorkflowValidator`
- **THEN** the publish action SHALL be blocked and a confirmation dialog SHALL list the blocking issues
- **AND** the prior published version SHALL remain active

#### Scenario: Publish succeeds and deprecates prior version
- **WHEN** the administrator clicks "Publiceren" on a draft with no error-level issues
- **AND** confirms the publish dialog
- **THEN** the editor SHALL call `POST /api/workflow-definition/{draftId}/publish`
- **AND** the response SHALL carry `isDraft: false`, `isActive: true`, and the previous active version SHALL be deprecated by the backend service
- **AND** in-flight cases pinned to the previous version SHALL continue to use that version

### Requirement: Import/Export Round-Trip
The system SHALL allow administrators to import a `workflowTemplate` JSON document onto the canvas and to export the current working copy as a JSON file conforming to the same schema. The round-trip SHALL preserve all fields covered by the `workflowTemplate` schema.

**Feature tier**: V1

#### Scenario: Import a valid template
- **WHEN** an administrator uploads a JSON file that validates against the `workflowTemplate` schema
- **THEN** every status, transition, step, guard, and routing rule in the file SHALL be rendered on the canvas
- **AND** if the file lacks a `layout` block the editor SHALL auto-layout via `dagre`

#### Scenario: Import refuses invalid JSON
- **WHEN** an administrator uploads a JSON file that fails schema validation
- **THEN** the editor SHALL refuse the import
- **AND** SHALL display the first validation error with the affected JSON pointer
- **AND** SHALL leave the current working copy unchanged

#### Scenario: Export round-trip is structurally identical
- **WHEN** an administrator exports the current working copy to JSON
- **AND** then imports the same file into a fresh editor instance
- **THEN** the resulting canvas SHALL contain the same nodes, edges, steps, guards, routing rules, and `layout` as the original
- **AND** the exported and re-exported JSON SHALL be byte-identical after normalising key order

### Requirement: Read-Only Preview of Published Versions
The system SHALL render any published workflow version in read-only preview mode by default: the palette SHALL be hidden, the canvas SHALL refuse drag/edit/delete interactions, and properties-panel fields SHALL render as disabled. The administrator SHALL flip to edit mode only via an explicit "Bewerken" action that creates a new draft.

**Feature tier**: V1

#### Scenario: Preview mode hides authoring affordances
- **WHEN** an administrator opens the editor for a zaaktype whose active workflow is published
- **THEN** the palette SHALL be hidden
- **AND** the canvas SHALL ignore drag-to-create and connection-drag attempts
- **AND** the properties panel SHALL render every input as disabled

#### Scenario: Switching to edit mode creates a draft
- **WHEN** the administrator clicks "Bewerken" in preview mode
- **AND** no draft currently exists
- **THEN** the editor SHALL create a fresh draft (see save-as-version flow)
- **AND** the palette SHALL become visible, the canvas interactive, and the properties panel editable
