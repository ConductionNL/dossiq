---
status: done
---

# dossiq-case-management Specification

## Purpose

@e2e exclude Superseded by canonical case-management and task-management specs; data model tests covered by PHPUnit.

Define the core case management domain for Dossiq: cases, tasks, statuses, roles, results, and decisions. All entities are stored in OpenRegister under the Dossiq register. The frontend provides list and detail views for cases and tasks, with case type configuration, status lifecycle management, deadline tracking, participant management, and activity timelines.

## Context
Dossiq implements zaakgericht werken (case-based working) for Dutch municipalities and organizations using Nextcloud. Cases represent formal processes (e.g., permit applications, complaints, information requests) that follow a configurable lifecycle. Each case has a type (zaaktype) that defines its status flow, processing deadline, confidentiality level, required roles, and available result/decision types. The architecture follows CMMN 1.1 concepts (CasePlanModel, HumanTask, Milestone) mapped to Schema.org types and compatible with ZGW APIs for Dutch government interoperability. All data is stored in OpenRegister; Dossiq adds no database tables.

## Requirements

### Requirement 1: Register and schemas MUST be auto-configured on install
The app MUST create or detect the Dossiq register and all required schemas in OpenRegister during app initialization via a repair step.

#### Scenario 1.1: First install creates register and schemas
- GIVEN OpenRegister is active and no Dossiq register exists
- WHEN the Dossiq app is enabled for the first time and `InitializeSettings` repair step runs
- THEN `SettingsService::loadConfiguration()` MUST import `lib/Settings/dossiq_register.json` via `ConfigurationService::importFromApp()`
- AND it MUST create the Dossiq register and schemas for: case, task, status, role, result, decision, caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType, and all supporting schemas
- AND `autoConfigureAfterImport()` MUST store register and schema IDs in `IAppConfig`

#### Scenario 1.2: Upgrade with newer version imports new schemas
- GIVEN a Dossiq register exists from version 1.0.0
- WHEN the app is upgraded and the register JSON declares version 1.1.0
- THEN `ConfigurationService::importFromApp()` MUST detect the version change and import updates
- AND new schemas (if any) MUST be added without losing existing data
- AND existing schema IDs MUST remain valid

#### Scenario 1.3: Settings endpoint returns register/schema configuration
- GIVEN the register is configured
- WHEN a GET request is made to `/apps/dossiq/api/settings`
- THEN the response MUST include `register` (register ID), `case_schema`, `task_schema`, `status_schema`, `role_schema`, `result_schema`, `decision_schema`, `case_type_schema`, `status_type_schema`, `result_type_schema`, `role_type_schema`, `property_definition_schema`, `document_type_schema`, `decision_type_schema`, and `default_case_type`
- AND the `openRegisters` field MUST be `true`

#### Scenario 1.4: Admin can save schema configuration
- GIVEN an admin user
- WHEN a POST request is made to `/apps/dossiq/api/settings` with updated schema IDs
- THEN `SettingsService::updateSettings()` MUST persist the values in `IAppConfig`
- AND the response MUST return the updated configuration

#### Scenario 1.5: Missing OpenRegister detected gracefully
- GIVEN OpenRegister is NOT installed
- WHEN `SettingsService::loadConfiguration()` is called
- THEN it MUST return `{ success: false, message: 'OpenRegister is not installed or enabled' }`
- AND the App.vue MUST display the missing-dependency screen

### Requirement 2: Cases list view MUST display paginated, searchable case overview
The frontend MUST display a list of cases with search, sort, filter, sidebar, and quick status change capabilities.

#### Scenario 2.1: Case list rendering with CnIndexPage
- GIVEN the user navigates to `/apps/dossiq/cases`
- WHEN `CaseList.vue` mounts and `useListView('case')` initializes
- THEN the composable MUST fetch the case schema and initial collection from OpenRegister
- AND `CnIndexPage` MUST render a data table with columns for identifier, title, case type, status, assignee, deadline
- AND the list MUST support pagination with `@page-changed` event

#### Scenario 2.2: Case search via sidebar
- GIVEN the CaseList activates the sidebar via `sidebarState`
- WHEN the user types "omgevingsvergunning" in the sidebar search
- THEN the search term MUST be passed to `fetchCollection('case', { _search: 'omgevingsvergunning' })`
- AND the list MUST update to show only matching cases

#### Scenario 2.3: Quick status change in list
- GIVEN a case row in the list with a `QuickStatusDropdown` component
- WHEN the user selects a new status from the dropdown (clicking the status cell)
- THEN the dropdown MUST show all status types for that case's case type, ordered by `order` field
- AND selecting a new status MUST save the status change to OpenRegister
- AND the list MUST refresh via `onQuickStatusChanged()` -> `refresh()`

#### Scenario 2.4: Deadline column with color coding
- GIVEN a case with a deadline
- WHEN the case list renders the deadline column
- THEN overdue cases MUST show red text with days overdue count (via `formatDeadlineCountdown()`)
- AND cases due today MUST show warning-colored text
- AND cases due tomorrow MUST show warning-colored text
- AND cases with time remaining MUST show green text
- AND closed cases (final status) MUST show gray text

#### Scenario 2.5: Overdue row highlighting
- GIVEN a case that is overdue and NOT at a final status
- WHEN the case list renders the row
- THEN `getRowClass()` MUST return `'row--overdue'` via `isCaseOverdue()`
- AND the row MUST have a `3px solid var(--color-error)` left border

### Requirement 3: Case create dialog MUST support type-driven case creation
The frontend MUST provide a dialog for creating new cases with case type selection, auto-calculated deadline, and initial status assignment.

#### Scenario 3.1: Open create dialog from case list
- GIVEN the user is on the cases list
- WHEN the user clicks the "+" add button (emitted by CnIndexPage as `@add`)
- THEN `CaseCreateDialog.vue` MUST open as a modal overlay
- AND it MUST load all available case types via `fetchCollection('caseType', { _limit: 100 })`

#### Scenario 3.2: Case type selection shows preview
- GIVEN the create dialog is open and case types are loaded
- WHEN the user selects a case type from the `NcSelect` dropdown
- THEN the preview panel MUST display: processing deadline (`formatDuration()`), confidentiality, initial status (first by `order`), and calculated deadline date (`calculateDeadline()` from today)
- AND status types MUST be fetched for the selected case type via `fetchCollection('statusType', { '_filters[caseType]': caseType.id })`

#### Scenario 3.3: Only usable case types shown
- GIVEN some case types have missing required configuration (no status types, no processing deadline)
- WHEN the case type dropdown renders
- THEN `usableCaseTypes` MUST filter using `isCaseTypeUsable()` from `caseValidation.js`
- AND non-usable case types MUST NOT appear in the dropdown

#### Scenario 3.4: Successful case creation
- GIVEN the user fills in title (required) and description (optional) and selects a case type
- WHEN the user clicks "Create case"
- THEN `validateCaseCreate()` MUST pass
- AND the case MUST be saved via `saveObject('case', caseData)` with fields: title, description, identifier (generated by `generateIdentifier()`), caseType, status (initial status ID), startDate (today), deadline (calculated), confidentiality (from case type), assignee (null), priority ('normal'), endDate (null), result (null), extensionCount (0), statusHistory (initial entry), activity (created entry)
- AND after creation, the router MUST navigate to `CaseDetail` with the new case ID

#### Scenario 3.5: Validation error on missing title
- GIVEN the user has selected a case type but left the title empty
- WHEN the user clicks "Create case"
- THEN `validateCaseCreate()` MUST return `{ valid: false, errors: { title: '...' } }`
- AND the title field MUST display the error in red below the input
- AND the form MUST NOT submit

### Requirement 4: Case detail view MUST display full case information with related data
The frontend MUST provide a comprehensive case detail view with status management, information editing, deadline tracking, participants, tasks, and activity timeline.

#### Scenario 4.1: Case detail page load
- GIVEN the user navigates to `/apps/dossiq/cases/:id`
- WHEN `CaseDetail.vue` mounts
- THEN it MUST fetch the case via `fetchObject('case', caseId)`
- AND it MUST call `loadCaseTypeData()` to fetch the case type and its status types and result types
- AND it MUST call `fetchTasks()` to get tasks filtered by case ID
- AND it MUST call `fetchCaseResult()` to get an existing result (if any)

#### Scenario 4.2: Case detail renders CnDetailPage with cards
- GIVEN the case data is loaded
- WHEN the detail view renders
- THEN it MUST use `CnDetailPage` with title, subtitle (identifier), back navigation to Cases list, and sidebar
- AND it MUST render `CnDetailCard` sections for: Status, Status Timeline, Case Information, Deadline & Timing, Participants, Tasks, Activity

#### Scenario 4.3: Status card shows current status and change dropdown
- GIVEN the case has a current status
- WHEN the Status card renders
- THEN it MUST display the current status name in a badge (active=blue, final=green)
- AND if the case is not at a final status, it MUST show an `NcSelect` dropdown with ordered status types
- AND selecting a status MUST trigger `onStatusSelected()` which either executes the change directly (non-final) or shows the result prompt (final status)

#### Scenario 4.4: Case information form with editing
- GIVEN the case is not at a final status (not read-only)
- WHEN the case information card renders
- THEN it MUST show editable fields for: title (required, `NcTextField`), description (textarea), priority (`NcSelect` with low/normal/high/urgent), assignee (`NcTextField`)
- AND read-only fields for: case type name, identifier, confidentiality, start date
- AND the Save button MUST call `save()` which validates via `validateCaseUpdate()` and saves via `saveObject('case', updateData)` with activity tracking for changed fields

#### Scenario 4.5: Read-only mode when case is closed
- GIVEN a case whose current status type has `isFinal === true`
- WHEN the detail view renders
- THEN `isReadOnly` MUST be `true`
- AND all form fields MUST be disabled
- AND the Save button MUST NOT appear
- AND the "New task" button MUST NOT appear
- AND the status card MUST show the status badge with `--final` class

### Requirement 5: Status lifecycle MUST support configurable status flows with mandatory result on closure
The case detail view MUST enforce status transitions including requiring a result when moving to a final status.

#### Scenario 5.1: Non-final status change (direct)
- GIVEN a case at status "Registratie" and the user selects "In behandeling" (a non-final status)
- WHEN `onStatusSelected(status)` is called
- THEN it MUST call `executeStatusChange(status)` directly (no result prompt)
- AND `executeStatusChange` MUST update: `status` (new ID), `statusHistory` (push new entry with date and changedBy), `activity` (push status_change entry)
- AND the updated case MUST be saved via `saveObject('case', updateData)`

#### Scenario 5.2: Final status requires result selection (typed)
- GIVEN result types exist for the case type
- WHEN the user selects a final status (isFinal === true)
- THEN `showResultPrompt` MUST be set to `true`
- AND the prompt MUST display an `NcSelect` with available result types
- AND the user MUST select a result type before confirming
- AND on confirm, `confirmStatusChange()` MUST create a result object via `saveObject('result', { name, case, resultType })` then execute the status change with result text

#### Scenario 5.3: Final status requires free-text result (no types)
- GIVEN no result types are configured for the case type
- WHEN the user selects a final status
- THEN the result prompt MUST show an `NcTextField` for free-text result input
- AND the result MUST NOT be empty (validation error: "Result is required when closing a case")
- AND the result text MUST be stored in `caseData.result`

#### Scenario 5.4: Cancel status change
- GIVEN the result prompt is showing
- WHEN the user clicks "Cancel"
- THEN `cancelStatusChange()` MUST hide the prompt, clear the pending status, and reset the dropdown
- AND no changes MUST be persisted

#### Scenario 5.5: Status change sets endDate on closure
- GIVEN a final status is confirmed with a result
- WHEN `executeStatusChange()` runs
- THEN `updateData.endDate` MUST be set to today at 17:00:00Z
- AND `updateData.result` MUST contain the result text

### Requirement 6: Deadline and timing MUST support processing deadlines with extensions
The case detail view MUST display deadline information and support deadline extensions per case type configuration.

#### Scenario 6.1: Deadline panel rendering
- GIVEN a case with a case type that has `processingDeadline: 'P8W'` (8 weeks)
- WHEN the Deadline & Timing card renders
- THEN `DeadlinePanel.vue` MUST display: start date, current deadline, processing deadline duration, and remaining time
- AND if the deadline has passed, it MUST show the overdue duration in red

#### Scenario 6.2: Extension allowed check
- GIVEN a case type with `extensionAllowed: true` and `extensionPeriod: 'P6W'`
- WHEN the deadline panel renders
- THEN an "Extend deadline" button MUST be visible
- AND the button MUST show the extension period duration

#### Scenario 6.3: Confirm deadline extension
- GIVEN the user clicks "Extend deadline" and the extension dialog opens
- WHEN the user enters a reason and clicks "Extend deadline"
- THEN `confirmExtension()` MUST calculate the new deadline via `calculateDeadline(currentDeadline, extensionPeriod)`
- AND the case MUST be updated with: new deadline, incremented `extensionCount`, and activity entry of type `extension` including old deadline, new deadline, and reason

#### Scenario 6.4: Extension not allowed hides button
- GIVEN a case type with `extensionAllowed: false`
- WHEN the deadline panel renders
- THEN no extension button MUST appear

#### Scenario 6.5: Extension not available for closed cases
- GIVEN a case at a final status
- WHEN the deadline panel renders
- THEN the extension button MUST NOT appear (case is read-only)

### Requirement 7: Tasks MUST be manageable within case context
The case detail view MUST display and manage tasks linked to the case, with standalone task list and detail views also available.

#### Scenario 7.1: Task list within case detail
- GIVEN a case with 5 linked tasks
- WHEN the Tasks card in CaseDetail renders
- THEN it MUST fetch tasks via `fetchCollection('task', { '_filters[case]': caseId, _limit: 50 })`
- AND it MUST display a table with columns: Title, Status (badge), Assignee, Due date (with overdue/today indicators), Priority (badge for non-normal)
- AND the card title MUST show `Tasks (completed/total)` count
- AND tasks MUST be sorted via `sortTasks()` from `taskHelpers.js`

#### Scenario 7.2: Navigate to task detail from case
- GIVEN a task row in the case detail's task table
- WHEN the user clicks the row
- THEN the router MUST navigate to `TaskDetail` with the task's ID

#### Scenario 7.3: Create new task from case
- GIVEN the case is not read-only
- WHEN the user clicks "New task" in the task card actions
- THEN the router MUST navigate to `TaskNew` with `query: { caseId: caseId }`
- AND the new task form MUST pre-fill the case reference

#### Scenario 7.4: Overdue task highlighting
- GIVEN a task with a due date in the past and status not "completed"
- WHEN the task row renders
- THEN `isOverdue(task)` MUST return `true`
- AND the row MUST have `viewTableRow--overdue` class (red left border)
- AND the due date cell MUST show overdue text via `getOverdueText()` in red

#### Scenario 7.5: Standalone task list view
- GIVEN the user navigates to `/apps/dossiq/tasks`
- WHEN `TaskList.vue` mounts
- THEN it MUST display all tasks across all cases using `useListView('task')`
- AND it MUST support search, sort, and pagination like the case list

### Requirement 8: Participants MUST be manageable per case
The case detail view MUST allow managing participants (roles) linked to a case.

#### Scenario 8.1: Participants section rendering
- GIVEN a case with 3 participants (initiator, handler, advisor)
- WHEN the Participants card renders in CaseDetail
- THEN `ParticipantsSection.vue` MUST display each participant with their role type and name/identifier
- AND if the case is not read-only, add/remove actions MUST be available

#### Scenario 8.2: Add participant dialog
- GIVEN the user clicks "Add participant" in the participants section
- WHEN the `AddParticipantDialog.vue` opens
- THEN it MUST allow selecting a role type and entering participant details
- AND saving MUST create a new role object in OpenRegister linked to the case

#### Scenario 8.3: Handler participant syncs with case assignee
- GIVEN a participant with the "handler" role type is added to the case
- WHEN the handler participant changes
- THEN the `@handler-changed` event MUST be emitted from ParticipantsSection
- AND `onHandlerChanged()` in CaseDetail MUST update `form.assignee` and persist it to OpenRegister

### Requirement 9: Activity timeline MUST record all case events
The case detail view MUST display a chronological activity timeline with support for manual notes.

#### Scenario 9.1: Activity timeline rendering
- GIVEN a case with activity entries of types: created, status_change, update, extension, note
- WHEN the Activity card renders
- THEN `ActivityTimeline.vue` MUST display each entry chronologically (newest first)
- AND each entry MUST show: date, type icon, description, user who performed the action

#### Scenario 9.2: Add manual note
- GIVEN the case is not read-only
- WHEN the user types a note and clicks the add button in ActivityTimeline
- THEN `onAddNote(text)` MUST push a new entry of type `note` to the case's activity array
- AND the updated case MUST be saved via `saveObject('case', updateData)`

#### Scenario 9.3: Automatic activity on field changes
- GIVEN the user modifies title and priority in the case form
- WHEN the user clicks Save
- THEN the save method MUST detect changed fields by comparing form values to `caseData`
- AND it MUST push an activity entry of type `update` with description listing the changed field names (e.g., "Updated: title, priority")

#### Scenario 9.4: Status change activity
- GIVEN a status change from "In behandeling" to "Afgehandeld"
- WHEN `executeStatusChange()` runs
- THEN it MUST push an activity entry of type `status_change` with description "Status changed from 'In behandeling' to 'Afgehandeld'"

#### Scenario 9.5: Extension activity
- GIVEN a deadline extension is confirmed
- WHEN `confirmExtension()` runs
- THEN it MUST push an activity entry of type `extension` with description including old deadline, new deadline, and reason

### Requirement 10: Case type administration MUST support configuring case types
The admin settings MUST provide UI for creating and configuring case types with their status types, result types, role types, processing deadlines, and properties.

#### Scenario 10.1: Case type list view
- GIVEN the admin navigates to `/apps/dossiq/case-types`
- WHEN `CaseTypeAdmin.vue` renders
- THEN `CaseTypeList.vue` MUST display all case types from OpenRegister
- AND each case type MUST show its title, processing deadline, and status count

#### Scenario 10.2: Case type detail editing
- GIVEN the admin clicks a case type
- WHEN `CaseTypeDetail.vue` renders
- THEN it MUST display editable fields for: title, description, processingDeadline (ISO 8601 duration), confidentiality, extensionAllowed (boolean), extensionPeriod (ISO 8601 duration)
- AND it MUST include tabs/sections for managing the type's: status types (with ordering), result types, role types, decision types, document types

#### Scenario 10.3: Status type ordering
- GIVEN a case type with 5 status types
- WHEN the admin views the status types section in CaseTypeDetail
- THEN status types MUST be displayed in their `order` field sequence
- AND the admin MUST be able to reorder them
- AND exactly one status type MUST be markable as `isFinal`

#### Scenario 10.4: Processing deadline format
- GIVEN the admin enters a processing deadline
- WHEN they type "P8W" (8 weeks) or "P42D" (42 days)
- THEN the input MUST accept ISO 8601 duration format
- AND `formatDuration()` from `caseHelpers.js` MUST display it in human-readable form

#### Scenario 10.5: Default case type selection
- GIVEN the admin has configured multiple case types
- WHEN they set one as the default via settings
- THEN the `default_case_type` config key MUST be updated with the selected case type ID
- AND new cases created without explicit type selection SHOULD use this default

### Requirement 11: Navigation MUST include all primary views
The app navigation MUST show menu items for Dashboard, My Work, Cases, Tasks, and settings.

#### Scenario 11.1: Main navigation items
- GIVEN the user opens the Dossiq app
- WHEN `MainMenu.vue` renders within `NcAppNavigation`
- THEN the main list MUST include: Dashboard, My Work, Cases, Tasks, Documentation
- AND each item MUST use the correct Material Design icon

#### Scenario 11.2: Settings footer items
- GIVEN the navigation footer renders
- WHEN `NcAppNavigationSettings` renders
- THEN it MUST include: Case Types and Configuration items
- AND Case Types MUST route to the case type admin view
- AND Configuration MUST route to the general settings view

#### Scenario 11.3: My Work view
- GIVEN the user navigates to `/apps/dossiq/my-work`
- WHEN `MyWork.vue` renders
- THEN it MUST display cases and tasks assigned to the current user
- AND the view MUST filter by `assignee` matching `OC.currentUser`

### Requirement 12: Dashboard MUST provide overview metrics and quick access
The dashboard view MUST show key performance indicators, status charts, overdue cases, and recent activity.

#### Scenario 12.1: Dashboard page rendering
- GIVEN the user navigates to `/apps/dossiq/`
- WHEN `Dashboard.vue` renders
- THEN it MUST include components for: `KpiCards` (key metrics), `StatusChart` (status distribution), `MyWorkPreview` (user's assigned items), `OverduePanel` (overdue cases), `ActivityFeed` (recent activity)

#### Scenario 12.2: KPI cards show aggregate counts
- GIVEN the dashboard is loading
- WHEN `KpiCards.vue` fetches data
- THEN it MUST display at minimum: total open cases, cases due this week, overdue cases, my tasks count

#### Scenario 12.3: Status chart shows distribution
- GIVEN cases exist across multiple statuses
- WHEN `StatusChart.vue` renders
- THEN it MUST display a visual chart (bar or pie) showing case count per status
- AND clicking a status segment SHOULD navigate to the case list filtered by that status

#### Scenario 12.4: Overdue panel highlights urgent items
- GIVEN 3 cases are past their deadline
- WHEN `OverduePanel.vue` renders
- THEN each overdue case MUST be listed with title, days overdue, and handler
- AND clicking an item MUST navigate to the case detail

#### Scenario 12.5: Dashboard accessible as Nextcloud widgets
- GIVEN the Nextcloud Dashboard page
- WHEN the user adds Dossiq widgets
- THEN `CasesOverviewWidget`, `OverdueCasesWidget`, and `MyTasksWidget` MUST render in their respective widget containers
- AND each MUST use its dedicated widget entry point (not the full SPA bundle)

### Requirement 13: ZGW API compatibility MUST be maintained
The backend MUST provide ZGW-compatible API endpoints for Dutch government interoperability.

#### Scenario 13.1: ZGW controller layer
- GIVEN the ZGW API controllers (`ZrcController`, `ZtcController`, `BrcController`, `DrcController`, `AcController`, `NrcController`)
- WHEN a ZGW client sends a request
- THEN `ZgwAuthMiddleware` MUST authenticate the request via token
- AND the controller MUST translate ZGW field names to Dossiq field names via `ZgwMappingService`
- AND the response MUST follow ZGW API format conventions

#### Scenario 13.2: ZGW field mapping configuration
- GIVEN the ZGW mapping settings page (`ZgwMappingSettings.vue`)
- WHEN the admin configures field mappings
- THEN `zgwMapping.js` store MUST save mappings via `ZgwMappingController`
- AND `ZgwMappingService` MUST use these mappings to translate between ZGW and Dossiq field names

#### Scenario 13.3: ZGW business rules enforcement
- GIVEN `ZgwBusinessRulesService`, `ZgwBrcRulesService`, `ZgwZtcRulesService`, `ZgwZrcRulesService`, `ZgwDrcRulesService`
- WHEN a ZGW API request is processed
- THEN the relevant rules service MUST validate the request against ZGW specification constraints
- AND validation failures MUST return appropriate error responses

#### Scenario 13.4: ZGW pagination support
- GIVEN `ZgwPaginationHelper`
- WHEN a ZGW API client requests a paginated list
- THEN the response MUST follow ZGW pagination format (count, next, previous, results)

---

## Current Implementation Status

**Substantially implemented.** Core case management functionality is in place.

**Implemented (with file paths):**
- **Register auto-configuration**: `lib/Repair/InitializeSettings.php` via `SettingsService::loadConfiguration()`. Register defined in `lib/Settings/dossiq_register.json`.
- **Settings endpoint**: `lib/Controller/SettingsController.php` with GET/POST `/api/settings`. `lib/Service/SettingsService.php` manages 28 config keys with `SLUG_TO_CONFIG_KEY` mapping.
- **Cases list view**: `src/views/cases/CaseList.vue` using `useListView('case')` and `CnIndexPage` with custom column templates for identifier, caseType, status (QuickStatusDropdown), and deadline.
- **Case create dialog**: `src/views/cases/CaseCreateDialog.vue` with case type selection, preview, validation, identifier generation, and automatic deadline calculation.
- **Case detail view**: `src/views/cases/CaseDetail.vue` using `CnDetailPage` and `CnDetailCard` for Status, Status Timeline, Case Information, Deadline & Timing, Participants, Tasks, and Activity sections.
- **Status lifecycle**: Status transitions with result prompt for final statuses, status history tracking, endDate setting on closure.
- **Deadline management**: `src/views/cases/components/DeadlinePanel.vue` with extension support.
- **Participants**: `src/views/cases/components/ParticipantsSection.vue` and `AddParticipantDialog.vue`.
- **Activity timeline**: `src/views/cases/components/ActivityTimeline.vue` with note addition.
- **Task management**: `src/views/tasks/TaskList.vue`, `TaskDetail.vue`, `TaskCreateDialog.vue`. Tasks in case detail fetched via `fetchCollection('task', { '_filters[case]': caseId })`.
- **Navigation**: `src/navigation/MainMenu.vue` with Dashboard, My Work, Cases, Tasks, Documentation, Case Types, Configuration.
- **Dashboard**: `src/views/Dashboard.vue` with `KpiCards`, `StatusChart`, `MyWorkPreview`, `OverduePanel`, `ActivityFeed`.
- **Dashboard widgets**: `lib/Dashboard/CasesOverviewWidget.php`, `OverdueCasesWidget.php`, `MyTasksWidget.php` with corresponding Vue components.
- **Case type admin**: `src/views/settings/CaseTypeAdmin.vue`, `CaseTypeList.vue`, `CaseTypeDetail.vue`.
- **ZGW API layer**: 6 ZGW controllers, `ZgwAuthMiddleware`, `ZgwMappingService`, 4 rules services, `ZgwPaginationHelper`, `ZgwDocumentService`.
- **ZGW mapping UI**: `src/views/settings/ZgwMappingSettings.vue` with `src/store/modules/zgwMapping.js`.
- **Validation utilities**: `src/utils/caseValidation.js`, `src/utils/caseTypeValidation.js`.
- **Helper utilities**: `src/utils/caseHelpers.js`, `src/utils/taskHelpers.js`, `src/utils/taskLifecycle.js`, `src/utils/durationHelpers.js`, `src/utils/dashboardHelpers.js`.

**All 13 requirements are substantially implemented.**

## Standards & References

- **CMMN 1.1 (OMG)**: Case lifecycle concepts -- CasePlanModel (case), HumanTask (task), Milestone (status), Sentry (status transition guards).
- **Schema.org**: Cases typed as `schema:Project`, tasks as `schema:Action`, roles as `schema:Role`, results as `schema:ActionResult` in `dossiq_register.json`.
- **ZGW APIs (VNG Realisatie)**: Full ZGW API layer with controllers for Zaken (ZRC), Catalogi (ZTC), Besluiten (BRC), Documenten (DRC), Autorisaties (AC), Notificaties (NRC).
- **Common Ground**: Data layer in OpenRegister, process layer in Dossiq.
- **OpenAPI 3.0.0**: Register configuration format.
- **ISO 8601**: Duration format for processing deadlines and extension periods (e.g., P8W, P42D).
- **WCAG AA**: All views must be keyboard navigable and screen reader compatible.
- **NL Design System**: CSS variables for government theming via `nldesign` app.

## Specificity Assessment

This spec is comprehensive with 13 requirements covering the full case management domain. It documents register initialization, case CRUD with list and detail views, case creation with type-driven defaults, status lifecycle with mandatory results, deadline management with extensions, task management, participant management, activity timeline, case type administration, navigation, dashboard, and ZGW API compatibility. The spec accurately reflects the actual codebase structure and implementation patterns.
