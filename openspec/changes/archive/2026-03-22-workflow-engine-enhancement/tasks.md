## 1. Schema and Data Model Setup (V1)

- [x] 1.1 Add `workflowTemplate` schema definition to `lib/Settings/procest_register.json` with all properties from the workflow-definition-model spec (title, description, caseType, version, isActive, isDraft, steps, transitions, createdAt, updatedAt)
- [x] 1.2 Add `WorkflowStep` embedded object definition to the register schema with properties: id, title, description, status, order, assigneeRole, isRequired, checklist, automaticActions
- [x] 1.3 Add `StatusTransition` embedded object definition to the register schema with properties: id, fromStatus, toStatus, label, guards, automaticActions, allowedRoles
- [x] 1.4 Add guard type definitions (checklist, requiredField, requiredDocument, roleGuard) as JSON schema validation rules
- [x] 1.5 Add automatic action type definitions (sendEmail, createTask, createSubCase, webhook, setField, notify) as JSON schema validation rules
- [x] 1.6 Verify the repair step imports the new schema via ConfigurationService::importFromApp() and test with OpenRegister

## 2. Workflow Store Module (V1)

- [x] 2.1 Create Pinia store module `src/store/modules/workflow.js` with CRUD actions for workflow templates (list, get, create, update, delete) querying OpenRegister API
- [x] 2.2 Add workflow version management actions: publishVersion, createDraftFromVersion, listVersions, getActiveVersion
- [x] 2.3 Add transition evaluation logic: computeAvailableTransitions(caseData, userRoles) that filters transitions based on guards and role restrictions
- [x] 2.4 Add guard evaluation functions: evaluateChecklistGuard, evaluateRequiredFieldGuard, evaluateRequiredDocumentGuard, evaluateRoleGuard
- [x] 2.5 Add automatic action dispatch functions: dispatchEmailAction (via n8n webhook), dispatchCreateTaskAction, dispatchNotifyAction, dispatchWebhookAction

## 3. Visual Workflow Editor Components (V1)

- [x] 3.1 Create `src/views/settings/WorkflowEditor.vue` as the main canvas component with SVG-based node rendering and pan/zoom support
- [x] 3.2 Create `src/views/settings/components/WorkflowNode.vue` for draggable status nodes displaying name, step count badge, and connection ports
- [x] 3.3 Create `src/views/settings/components/WorkflowTransitionArrow.vue` for rendering directional arrows between nodes using SVG path elements
- [x] 3.4 Create `src/views/settings/components/WorkflowPalette.vue` with draggable elements (new status node, annotation) that can be dropped onto the canvas
- [x] 3.5 Implement drag-and-drop for adding new status nodes from palette to canvas and updating node positions on canvas
- [x] 3.6 Implement port-to-port connection drawing: drag from output port of one node to input port of another to create a StatusTransition
- [x] 3.7 Create `src/views/settings/components/WorkflowValidationBanner.vue` showing real-time validation errors (no final status, orphaned nodes, circular routes)

## 4. Step and Transition Configuration Panels (V1)

- [x] 4.1 Create `src/views/settings/components/StepConfigPanel.vue` side panel for editing step properties (title, description, isRequired, assigneeRole)
- [x] 4.2 Add checklist editor sub-component in StepConfigPanel with add/remove/reorder controls for checklist items
- [x] 4.3 Create `src/views/settings/components/TransitionConfigPanel.vue` side panel for editing transition properties (label, allowedRoles)
- [x] 4.4 Add guard configuration sub-component in TransitionConfigPanel supporting all 4 guard types with appropriate form controls
- [x] 4.5 Add automatic action configuration sub-component in TransitionConfigPanel with action type selector and type-specific fields (email template, webhook URL, task title, etc.)
- [x] 4.6 Implement step reordering within a status node via drag-and-drop with order property update

## 5. Workflow Tab Integration in CaseType Admin (V1)

- [x] 5.1 Add "Workflow" tab to `src/views/settings/CaseTypeDetail.vue` that loads the WorkflowEditor for the current zaaktype
- [x] 5.2 Add workflow version selector dropdown in the Workflow tab showing version history (version number, publish date, status)
- [x] 5.3 Add "Publiceren" button with validation (requires at least one status, one final status, no orphaned nodes) and version activation logic
- [x] 5.4 Add "Bewerken" button on published workflows that creates a new draft version via copy-on-write

## 6. Case Detail Workflow Integration (V1)

- [x] 6.1 Modify case creation flow to bind new cases to the active workflow version of their zaaktype (store workflowVersion reference on case object)
- [x] 6.2 Add transition buttons to case detail view computed from the bound workflow version, filtered by user role and guard evaluation
- [x] 6.3 Implement transition execution: update case status, create audit trail entry, dispatch automatic actions, refresh case data
- [x] 6.4 Add guard status indicators on transition buttons (disabled state with tooltip showing unmet conditions)
- [x] 6.5 Auto-create tasks from workflow steps when a case enters a new status (map step definitions to task objects in OpenRegister)
- [x] 6.6 Block status exit transitions when required steps (tasks) are not completed, with UI indicator showing remaining required steps
- [x] 6.7 Auto-terminate optional step tasks when a status transition exits their status phase

## 7. Versioning and Version Notice (V1)

- [x] 7.1 Display informational notice on case detail when the case's workflow version differs from the active version: "Dit dossier gebruikt werkstroomversie X. Huidige versie is Y."
- [x] 7.2 Implement version history view for administrators showing all versions with metadata and read-only access to archived versions

## 8. Import/Export (V1)

- [x] 8.1 Implement workflow export: generate JSON file with complete workflow definition using type names instead of UUIDs, including manifest of referenced types
- [x] 8.2 Implement workflow import: parse JSON file, match referenced types by name, create new draft version with target environment UUIDs
- [x] 8.3 Add import validation report showing missing types with option to auto-create or cancel
- [x] 8.4 Add "Exporteren" and "Importeren" buttons to the Workflow tab in CaseType admin

## 9. Role-Based Filtering (V1)

- [x] 9.1 Filter tasks in My Work / task lists based on user's case role matching step assigneeRole (steps with no role restriction visible to all case participants)
- [x] 9.2 Filter transition buttons on case detail based on user's case role matching transition allowedRoles

## 10. Workflow Inheritance (Enterprise)

- [x] 10.1 Add parent workflow reference field on child zaaktype workflow templates
- [x] 10.2 Implement inheritance resolution: child inherits parent steps with ability to override individual step role assignments
