---
status: done
---

# Case Dashboard View Specification

## OR Capability Citations

This spec consumes the following OpenRegister capabilities (per
ADR-022, dossiq-adopt-or-abstractions):

- `aggregations-backend-native` — KPI cards on the case dashboard bind
  directly to the aggregations endpoint via
  `x-openregister-aggregations` annotations on the case schema; no
  custom dashboard service. See
  `openregister/openspec/changes/aggregations-backend-native/`.

## Purpose

The Case Dashboard View is the primary working screen for behandelaars. It combines all relevant information for a single case into one integrated view: timeline, documents, status, tasks, contactmomenten, besluiten, and linked objects. While the Case Management spec (`../case-management/spec.md`) defines the data model and individual panels (REQ-CM-06 through REQ-CM-13), this spec defines how those panels are composed into a cohesive working screen with interactions between them.

**Tender demand**: This is not a separately tendered capability but underpins the 83% (57/69) that require "zaakgericht werken." Every tender evaluation includes a demo of the case detail screen. Usability of this view is the #1 factor in user acceptance.
**Relationship to existing specs**: This spec COMPOSES elements from `case-management` (panels), `task-management` (task section), `roles-decisions` (participants, decisions), and `dashboard` (app-level overview). It adds layout, interactions, and cross-panel behaviors.
**Feature tier**: MVP (layout, panel composition, navigation), V1 (configurable layout, quick actions, keyboard shortcuts, contactmomenten, linked objects)

**Competitive context**: Dimpact ZAC uses an Angular SPA with Material UI and a tabbed case detail view (zaak-view). Key features include: full audit trail in a history tab, WebSocket-driven real-time updates (screen events), BAG object linking, and betrokkenen management. The ZAC case view integrates with Solr for search and Flowable for process state. Dossiq uses the `CnDetailPage` layout from `@conduction/nextcloud-vue` with a sidebar model, providing a more Nextcloud-native feel.

## Layout

```
+-------------------------------------------------------------+
| [<- Cases]  Bouwvergunning Keizersgracht 100  [2026-042]    |
| ============= Status Timeline ============================== |
| * Ontvangen -> * In behandeling -> o Besluitvorming -> o Afg |
+------------------------------+------------------------------+
| LEFT COLUMN (60%)            | RIGHT COLUMN (40%)           |
|                              |                              |
| +---------------------------+| +---------------------------+|
| | Activity Timeline         || | Case Info Panel           ||
| | (newest first)            || | Type, Priority, Handler   ||
| | - Task assigned...        || | Confidentiality           ||
| | - Status changed...       || +---------------------------+|
| | - Document uploaded...    || +---------------------------+|
| | - Note added...           || | Deadline Panel            ||
| | - Contactmoment...        || | 15 days remaining         ||
| |                           || | Started: Jan 15           ||
| +---------------------------+| | Deadline: Mar 12          ||
|                              | +---------------------------+|
| +---------------------------+| +---------------------------+|
| | Documents                 || | Participants              ||
| | 3/5 required docs         || | Handler: Jan de Vries     ||
| | - Bouwtekening [ok]       || | Aanvrager: Petra Jansen   ||
| | - Constructie... [ok]     || +---------------------------+|
| +---------------------------+| +---------------------------+|
|                              | | Tasks  3/5                ||
|                              | | [v] Ontvangstbevestiging  ||
|                              | | [>] Review docs           ||
|                              | | [ ] Leges berekenen       ||
|                              | +---------------------------+|
|                              | +---------------------------+|
|                              | | Custom Properties         ||
|                              | | Bouwkosten: EUR 180,000   ||
|                              | | Oppervlakte: 180 m2       ||
|                              | +---------------------------+|
|                              | +---------------------------+|
|                              | | Decisions                 ||
|                              | | (no decisions yet)        ||
|                              | +---------------------------+|
|                              | +---------------------------+|
|                              | | Linked Objects            ||
|                              | | BAG: Keizersgracht 100    ||
|                              | | BRP: Petra Jansen         ||
|                              | +---------------------------+|
+------------------------------+------------------------------+
```

## Requirements

---

### REQ-CDV-01: Integrated Case Working Screen

The system MUST provide a single integrated view that combines all case-related information and actions, using the `CnDetailPage` component from `@conduction/nextcloud-vue`.

**Feature tier**: MVP

#### Scenario CDV-01a: Load case dashboard

- GIVEN case "Bouwvergunning Keizersgracht 100" (identifier "2026-042")
- WHEN the behandelaar navigates to the case (from case list, My Work, or direct URL)
- THEN the system MUST display all panels in a single scrollable view: status timeline (top), activity timeline (left), case info + deadline + participants + tasks + properties + decisions + documents + linked objects (right)
- AND all data MUST load within 3 seconds (including all panel data)
- AND the URL MUST be bookmarkable: `/apps/dossiq/cases/2026-042`

#### Scenario CDV-01b: Load case from different entry points

- GIVEN the case "2026-042" exists
- WHEN the behandelaar navigates from:
  - Case list: clicking the row in the case list
  - My Work: clicking a case item in the personal work queue
  - Werkvoorraad: clicking a case item in the team work queue
  - Direct URL: pasting `/apps/dossiq/cases/2026-042`
  - Notification: clicking a Nextcloud notification linking to the case
- THEN the same case dashboard MUST render in all cases
- AND the "Back" button MUST navigate to the entry point (not always the case list)

#### Scenario CDV-01c: Case not found

- GIVEN a user navigates to `/apps/dossiq/cases/nonexistent-id`
- THEN the system MUST display a 404 state: "Zaak niet gevonden"
- AND a "Terug naar overzicht" button MUST be available

#### Scenario CDV-01d: Loading state

- GIVEN case data is being fetched from OpenRegister
- WHEN the page renders before data arrives
- THEN skeleton placeholders MUST be shown for each panel card (not a single spinner)
- AND the status timeline, KPI cards, and panel headers MUST render immediately with skeleton content

---

### REQ-CDV-02: Cross-Panel Interactions

Actions in one panel MUST immediately reflect in other panels without requiring a page reload, using Pinia store reactivity.

**Feature tier**: MVP

#### Scenario CDV-02a: Status change updates timeline

- GIVEN the behandelaar changes status from "Ontvangen" to "In behandeling" via the status timeline
- THEN the status timeline dots MUST update (Ontvangen filled, In behandeling highlighted)
- AND the activity timeline MUST immediately show: "Status gewijzigd van 'Ontvangen' naar 'In behandeling'"
- AND if new tasks are auto-created by the status change, the tasks panel MUST update
- AND the case info panel MUST reflect any status-dependent field changes

#### Scenario CDV-02b: Document upload updates checklist

- GIVEN the behandelaar uploads a document "Welstandsadvies" via the documents panel
- THEN the documents checklist MUST update: "Welstandsadvies" changes from missing to present (checkmark)
- AND the completion count MUST update: "4/5 complete"
- AND the activity timeline MUST show: "Document 'Welstandsadvies' toegevoegd door [user]"

#### Scenario CDV-02c: Task completion updates progress

- GIVEN the behandelaar completes task "Review documenten" via the tasks panel
- THEN the task MUST show a checkmark and move to completed state
- AND the task count MUST update: "4/5"
- AND the activity timeline MUST show: "Taak 'Review documenten' afgerond door [user]"

#### Scenario CDV-02d: Participant change updates info panel

- GIVEN the behandelaar changes the handler from "Jan" to "Maria" via the participants panel
- THEN the case info panel MUST immediately update the handler display to "Maria"
- AND the activity timeline MUST show: "Behandelaar gewijzigd van Jan naar Maria"

#### Scenario CDV-02e: Decision creation updates decisions panel

- GIVEN the behandelaar creates a new besluit via the decisions panel
- THEN the decisions panel MUST immediately show the new besluit with: type, datum, toelichting
- AND the activity timeline MUST show: "Besluit vastgesteld: [besluit type]"
- AND if the besluit triggers a status change, the status timeline MUST update

---

### REQ-CDV-03: Quick Actions

The case dashboard MUST provide quick actions for the most common operations without opening modal dialogs.

**Feature tier**: MVP

#### Scenario CDV-03a: Quick status change

- GIVEN the case dashboard is open
- WHEN the behandelaar clicks the current status in the timeline
- THEN a dropdown MUST appear with available next statuses (from NcSelect)
- AND selecting a status MUST update immediately (inline, no modal)
- AND if the selected status is final (isFinal=true), a result prompt MUST appear

#### Scenario CDV-03b: Quick note addition

- GIVEN the activity timeline panel
- WHEN the behandelaar types in the "Add note" input and presses Enter
- THEN the note MUST be saved to the case's activity array via `objectStore.saveObject()`
- AND the note MUST appear at the top of the timeline with timestamp and user
- AND the input MUST clear for the next note

#### Scenario CDV-03c: Quick task creation

- GIVEN the tasks panel
- WHEN the behandelaar clicks "Nieuwe taak" and types a task title
- THEN a task MUST be created linked to the case with status "available"
- AND the task MUST appear in the tasks panel immediately
- AND the task MUST be navigable to its detail page

#### Scenario CDV-03d: Quick handler assignment

- GIVEN the participants section shows no handler assigned
- WHEN the behandelaar types a username in the handler field
- THEN the system MUST autocomplete from Nextcloud users
- AND selecting a user MUST immediately persist the assignment via `objectStore.saveObject()`

#### Scenario CDV-03e: Quick document upload

- GIVEN the documents panel
- WHEN the behandelaar drags a file onto the documents area or clicks "Upload"
- THEN the document MUST be uploaded to the Nextcloud Files folder for this case
- AND a case_document link MUST be created in OpenRegister
- AND the documents checklist MUST update

---

### REQ-CDV-04: Contactmomenten Integration

The case dashboard MUST display contactmomenten (contact moments) linked to the case, showing all interactions with the initiator/aanvrager.

**Feature tier**: V1

#### Scenario CDV-04a: Display contactmomenten in timeline

- GIVEN a case with 3 contactmomenten from Pipelinq:
  - Mar 1: Telefoon -- "Vraag over status aanvraag" (KCC medewerker: Anouk)
  - Feb 15: E-mail -- "Aanvullende documenten verstuurd" (Petra Jansen)
  - Jan 16: Balie -- "Aanvraag ingediend" (Petra Jansen)
- WHEN the behandelaar views the case dashboard
- THEN the contactmomenten MUST appear in the activity timeline, interleaved with other events by date
- AND each contactmoment MUST show: kanaal icon (telefoon/e-mail/balie), samenvatting, medewerker, datum
- AND the behandelaar MUST be able to click through to the full contactmoment in Pipelinq

#### Scenario CDV-04b: Contactmoment channel icons

- GIVEN contactmomenten with different channels
- THEN each channel MUST have a distinct icon: phone icon for telefoon, email icon for e-mail, person icon for balie, chat icon for chat
- AND the channel label MUST be shown as tooltip on hover

#### Scenario CDV-04c: No contactmomenten available

- GIVEN a case with no linked contactmomenten
- WHEN viewing the activity timeline
- THEN the timeline MUST still function normally showing only case-native events
- AND no "contactmomenten" section or empty state needs to be shown separately

---

### REQ-CDV-05: Linked Cases and Objects

The case dashboard MUST display linked cases (parent/child, related) and linked objects (BAG addresses, BRP persons).

**Feature tier**: V1

#### Scenario CDV-05a: Display sub-cases

- GIVEN a parent case "Bouwproject Centrum" with 2 sub-cases: "Sloopvergunning" (status: Afgehandeld) and "Bouwvergunning" (status: In behandeling)
- WHEN the behandelaar views the case dashboard
- THEN a "Gerelateerde zaken" section MUST show the sub-cases with: title, identifier, status badge, deadline
- AND each sub-case MUST be clickable to navigate to its own case dashboard
- AND the parent case MUST show a "Deelzaken" label; sub-cases MUST show "Hoofdzaak: [parent title]"

#### Scenario CDV-05b: Display linked BAG object

- GIVEN a case linked to a BAG nummeraanduiding "Keizersgracht 100, 1015 AA Amsterdam"
- WHEN the behandelaar views the case dashboard
- THEN the "Gekoppelde objecten" panel MUST show: type "BAG Adres", identifier, full address
- AND clicking the object MUST open its detail in a sidebar or new view
- AND the data MUST be fetched from the BAG mock register in OpenRegister

#### Scenario CDV-05c: Display linked BRP person

- GIVEN a case linked to a BRP persoon BSN "999993653" (Suzanne Moulin)
- WHEN the behandelaar views the case dashboard
- THEN the "Gekoppelde objecten" panel MUST show: type "BRP Persoon", naam, BSN (partially masked: ***93653)
- AND clicking MUST open the person details (if authorized)

#### Scenario CDV-05d: Add linked object

- GIVEN the behandelaar wants to link a BAG address to the case
- WHEN clicking "Object koppelen" in the linked objects panel
- THEN a search dialog MUST allow searching BAG addresses by postcode, huisnummer, or straatnaam
- AND selecting a result MUST create a `caseObject` link in OpenRegister
- AND the linked objects panel MUST update immediately

#### Scenario CDV-05e: No linked objects

- GIVEN a case with no linked objects
- WHEN viewing the case dashboard
- THEN the linked objects panel MUST show: "Geen gekoppelde objecten" with an "Object koppelen" button

---

### REQ-CDV-06: Document Checklist Panel

The case dashboard MUST display a document checklist showing required and uploaded documents per case type.

**Feature tier**: V1

#### Scenario CDV-06a: Display required documents

- GIVEN a case type "Omgevingsvergunning Bouw" with required documents: bouwtekening, constructieberekening, situatietekening, welstandsadvies, foto's bestaande situatie
- AND 3 of 5 documents have been uploaded
- WHEN the behandelaar views the documents panel
- THEN each required document type MUST show: name, status (uploaded/missing), upload date (if uploaded)
- AND the completion count MUST show: "3/5 documenten compleet"
- AND missing documents MUST be visually distinct (greyed out or with warning icon)

#### Scenario CDV-06b: Upload document to checklist slot

- GIVEN the "Welstandsadvies" slot is empty
- WHEN the behandelaar clicks the upload button next to "Welstandsadvies"
- THEN a file picker MUST open (Nextcloud file picker or drag-and-drop zone)
- AND the uploaded file MUST be stored in the case's document folder in Nextcloud Files
- AND a `caseDocument` record MUST be created linking the file to the document type

#### Scenario CDV-06c: Additional (non-required) documents

- GIVEN the case has 2 additional documents uploaded that don't match a required type
- WHEN viewing the documents panel
- THEN the additional documents MUST be listed separately under "Overige documenten"
- AND each document MUST show: filename, size, upload date, uploader

---

### REQ-CDV-07: Responsive Layout

The case dashboard MUST be usable on different screen sizes, following Nextcloud's responsive design patterns.

**Feature tier**: MVP

#### Scenario CDV-07a: Desktop layout (>1200px)

- GIVEN a desktop screen with width 1440px
- THEN the layout MUST use the two-column layout (60/40 split) as shown in the wireframe
- AND all panels MUST render side-by-side

#### Scenario CDV-07b: Tablet layout (768-1200px)

- GIVEN a tablet screen with width 1024px
- THEN the layout MUST stack panels in a single column: status timeline, case info, deadline, activity timeline, tasks, documents, participants, properties, decisions, linked objects
- AND touch targets MUST be at least 44x44px per WCAG AA

#### Scenario CDV-07c: Print view

- GIVEN the behandelaar pressing Ctrl+P on the case dashboard
- THEN the print layout MUST show all case information in a clean, printable format
- AND the status timeline MUST be rendered as a text list (not interactive dots)
- AND action buttons (Save, Delete) MUST be hidden in print view
- AND the print output MUST include a header with case identifier, date printed, and Dossiq branding

---

### REQ-CDV-08: Keyboard Navigation

The case dashboard SHALL support keyboard shortcuts for power users, consistent with Nextcloud keyboard shortcut conventions.

**Feature tier**: V1

#### Scenario CDV-08a: Keyboard shortcuts

- GIVEN the case dashboard is focused
- THEN the following shortcuts MUST work:
  - `N` -- focus the "Add note" input in the activity timeline
  - `T` -- focus the "Add task" input in the tasks panel
  - `S` -- open the status change dropdown
  - `D` -- open the document upload dialog
  - `Esc` -- close any open dropdown or dialog
  - `?` -- show keyboard shortcut help overlay

#### Scenario CDV-08b: Shortcut conflicts

- GIVEN the user is typing in a text input (note, task title, etc.)
- WHEN pressing shortcut keys (N, T, S, D)
- THEN the shortcuts MUST NOT fire while a text input has focus
- AND only `Esc` MUST work to blur the input

#### Scenario CDV-08c: Shortcut help overlay

- GIVEN the user presses `?`
- THEN a modal MUST display all available shortcuts with descriptions
- AND pressing `Esc` or `?` again MUST close the overlay

---

### REQ-CDV-09: Custom Properties Panel

The case dashboard MUST display case-specific custom properties defined by the case type's property definitions.

**Feature tier**: V1

#### Scenario CDV-09a: Display custom properties

- GIVEN a case type "Omgevingsvergunning" with property definitions: bouwkosten (currency), oppervlakte (number + unit m2), aantal bouwlagen (integer)
- AND the case has values: bouwkosten = 180000, oppervlakte = 180, aantal bouwlagen = 3
- WHEN viewing the custom properties panel
- THEN each property MUST show: label, formatted value (EUR 180.000, 180 m2, 3)
- AND the formatting MUST respect the property definition type

#### Scenario CDV-09b: Edit custom properties

- GIVEN the behandelaar has edit permissions and the case is not at final status
- WHEN clicking the edit icon on a property
- THEN an inline editor MUST appear matching the property type: number input for numbers, text input for text, date picker for dates
- AND saving MUST persist the value to the case_property schema in OpenRegister

#### Scenario CDV-09c: No custom properties defined

- GIVEN a case type with no property definitions
- THEN the custom properties panel MUST NOT be rendered (hide completely)

---

### REQ-CDV-10: Save and Validation

The case dashboard MUST validate edits before saving and provide clear feedback on validation errors.

**Feature tier**: MVP

#### Scenario CDV-10a: Validate required fields

- GIVEN the behandelaar clears the case title (required field) and clicks Save
- THEN a validation error MUST appear: "Titel is verplicht"
- AND the save MUST NOT proceed
- AND the error MUST appear inline next to the title field (not as a toast)

#### Scenario CDV-10b: Successful save

- GIVEN the behandelaar edits the title and description and clicks Save
- THEN the system MUST persist via `objectStore.saveObject('case', updateData)`
- AND a success indication MUST appear (green checkmark or brief toast)
- AND the activity timeline MUST record: "Bijgewerkt: title, description"

#### Scenario CDV-10c: Concurrent edit conflict

- GIVEN two behandelaars are editing the same case simultaneously
- AND user A saves first, then user B tries to save
- WHEN user B's save encounters a version conflict
- THEN the system MUST notify user B: "De zaak is ondertussen gewijzigd door een ander. Vernieuw de pagina om de laatste versie te zien."
- AND user B's changes MUST NOT overwrite user A's changes

---

### REQ-CDV-11: Read-Only Mode

The case dashboard MUST render in read-only mode when the case is at a final status or the user lacks edit permissions.

**Feature tier**: MVP

#### Scenario CDV-11a: Final status read-only

- GIVEN a case at final status "Afgehandeld"
- WHEN viewing the case dashboard
- THEN all form inputs MUST be disabled
- AND the Save button MUST be hidden
- AND the status dropdown MUST NOT allow changes
- AND the result MUST be displayed prominently

#### Scenario CDV-11b: Reopened case becomes editable

- GIVEN a case that was "Afgehandeld" is reopened (if supported)
- WHEN the status changes back to a non-final status
- THEN the case MUST become editable again
- AND the Save button MUST reappear

---

### REQ-CDV-12: Delete Case

The case dashboard MUST support deleting a case with appropriate warnings.

**Feature tier**: MVP

#### Scenario CDV-12a: Delete case with linked tasks

- GIVEN a case with 5 linked tasks
- WHEN the behandelaar clicks "Verwijderen"
- THEN a confirmation dialog MUST appear: "Deze zaak heeft 5 gekoppelde taken. Weet u zeker dat u deze zaak wilt verwijderen?"
- AND confirming MUST delete the case and navigate to the case list

#### Scenario CDV-12b: Delete case without tasks

- GIVEN a case with no linked tasks
- WHEN the behandelaar clicks "Verwijderen"
- THEN a simpler confirmation: "Weet u zeker dat u deze zaak wilt verwijderen?"
- AND confirming MUST delete and navigate to the case list

#### Scenario CDV-12c: Delete case at final status

- GIVEN a case at final status "Afgehandeld"
- THEN the Delete button MUST still be available (cases may need to be purged)
- BUT a stronger warning MUST be shown: "Deze zaak is afgehandeld. Verwijderen is onomkeerbaar."

### Requirement: The case names itself in the header (REQ-CDV-14)

You see which case you are on without reading the data widget. The page SHALL
carry the case number, the case type and the deadline on its first layout row,
with the hours card beside them at the head of the right column, and SHALL
carry each of them on a CONFIGURED library widget rather than on a component
this app writes: no tile on that row may span it. The number and the type are
`stat` tiles in object-field mode, reading the fact straight off the loaded
record and resolving the case type through the store to its name, never its
uuid. The deadline SHALL read as a countdown in days left, or days overdue,
banding at 14 days and at 5; a case without a deadline SHALL show no remaining
time and no band. The status and the assignee are not tiles: the status reads
on the stages widget beside the panels, which is also where a case is moved,
and both read in the Data tab, the open tab on load. A row of five tiles
carried two the page did not need, which is the layout laid out by hand in
Buildiq edit mode on 2026-09-12 and copied into the manifest. Loose cells
rather than one row widget, because one widget drawing the facts inside a
two-row cell clipped them and read as a single strip, and because a cell is
what Buildiq edit mode can move.

`CaseDetail` SHALL also set `config.subtitleField` to `identifier`. The key is
inert on a detail page in @conduction/nextcloud-vue 2.49, which reads it only
in `CnObjectRow`, `CnIndexPage` and `CnObjectList`, so the number reads on the
page because the first tile prints it. The declaration stays so the day the
library honours it is a deletion rather than a new idea.

#### Scenario: The number reads under the title
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a case with identifier 2026-0015 and title Aanbouw Beethovenlaan 8
- **WHEN** the handler opens the case page
- **THEN** the page title SHALL read Aanbouw Beethovenlaan 8
- **AND** the subtitle SHALL read 2026-0015

#### Scenario: Status and deadline sit in the header row
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a case in status In behandeling with a deadline 26 days ago
- **WHEN** the handler opens the case page
- **THEN** the Status field in the Data tab SHALL read In behandeling, and never the uuid
- **AND** the Deadline tile SHALL show 26 days overdue in the danger variant
- **AND** no tile labelled Time left SHALL render in the KPI row

#### Scenario: A case without a status or a deadline still has a header
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a case with no status record and no deadline
- **WHEN** the handler opens the case page
- **THEN** the Status field in the Data tab SHALL render, with no uuid and no fabricated name
- **AND** the Deadline tile SHALL show no count of days

#### Scenario: The full subtitle line waits on nextcloud-vue
@e2e exclude The templated subtitle (identifier, case type, assignee) needs a `subtitle` template on `CnDetailPage`, which 2.40.0 does not have; the manifest unit test asserts the interim `subtitleField` and the tasks.md marker tracks the block.

- **GIVEN** nextcloud-vue ships a templated `subtitle` on the detail page
- **WHEN** `CaseDetail` sets it to identifier, case type and assignee
- **THEN** the subtitle SHALL read 2026-0015, Omgevingsvergunning, Jan de Vries

### Requirement: The case page carries no breadcrumb (REQ-CDV-15)

The case page SHALL NOT render a breadcrumb trail. The way back to the case
list is the app menu, which every other detail page in this app uses.

This requirement used to say the opposite: `CaseDetail` declared a trail of
Cases followed by the case title, rendered through `CnBreadcrumbs` above the
title. It was withdrawn because the trail's LAST crumb was the case title,
rendered one line below the page header that already printed that title, so
the page read `Cases > Dakkapel Kerkstraat 12` directly under
`Dakkapel Kerkstraat 12`. A trail whose final segment repeats the heading
beside it states a location the reader is already looking at, and it cost the
top of the page a row that the content below it needed.

It is stated as a requirement rather than simply deleted so the absence is
legible: without it the next reader finds the `_breadcrumbsNote` in the
manifest, reads it as an oversight, and adds the trail back.

#### Scenario: No trail is rendered
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a case titled Aanbouw Beethovenlaan 8
- **WHEN** the handler opens the case page
- **THEN** the page SHALL render no breadcrumb trail
- **AND** no element SHALL carry both the case title and `aria-current="page"`,
  which is the signature the trail's last crumb left

### Requirement: The case shows its step (REQ-CDV-13)

You see which step the case is in and how many steps remain, and you move the
case by clicking where you are moving it to. `CaseDetail` SHALL place a
CONFIGURED timeline widget over the case type's status types in their `order`,
with the current status marked as active and the statuses before it as done.
The list SHALL come from the case type's BLUEPRINT, so a type that derives its
lifecycle from a parent shows the statuses it inherited rather than none. The
stages SHALL be read from a declaration rather than from a component this app
writes, and the timeline SHALL take the place of the milestone progress tile.
Clicking a stage SHALL take the move that leads there, under the same guards
the engine applies to any other move.

#### Scenario: The current step is marked
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a case type with statuses Ontvangen, In behandeling, Aangehouden, Afgehandeld in that order
- **AND** a case in status In behandeling
- **WHEN** the handler opens the case page
- **THEN** the timeline SHALL show one stage per status, in that order
- **AND** Ontvangen SHALL be done, In behandeling active, the rest pending
- **AND** the stage the case is on SHALL be the one a screen reader is told about

#### Scenario: The stepper follows a transition
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** the same case
- **WHEN** the handler clicks the Afgehandeld stage and gives the result it asks for
- **THEN** the timeline SHALL mark Afgehandeld as active without a reload
- **AND** In behandeling SHALL read as done

#### Scenario: The progress tile is gone
@e2e tests/e2e/case-detail-kpis-and-tabs.spec.ts

- **WHEN** the handler opens any case page
- **THEN** no tile labelled Completed SHALL render in the KPI row
- **AND** the page SHALL make no request to `/milestones/progress`

### Requirement: The case keeps one history (REQ-CDV-17)

You read what happened on the case in one list, newest first, with who did
it. The `CaseDetail` sidebar SHALL carry exactly one history tab: the
`audit` tab labelled History, rendering `CnAuditTrailTab` over the case's
OpenRegister audit trail. The `version-history` tab
(`VersionHistoryLeafTab`) SHALL be absent from the `CaseDetail` sidebar.
Other detail pages keep their version history tab; this requirement
covers the case page only. Each row SHALL show the action, the user and
the time, and the list SHALL order newest first.

#### Scenario: One history tab in the sidebar
@e2e tests/e2e/case-timeline.spec.ts

- **GIVEN** a case with identifier 2026-0015
- **WHEN** the handler opens the case page and opens the sidebar
- **THEN** the sidebar SHALL offer a tab with id `audit`
- **AND** no tab with id `version-history` SHALL be present

#### Scenario: The newest write reads first, with its actor
@e2e tests/e2e/case-timeline.spec.ts

- **GIVEN** a case whose description admin changed one minute ago
- **WHEN** the handler opens the History tab
- **THEN** the first row SHALL read action update, user admin
- **AND** the row that created the case SHALL sit below it

#### Scenario: Other detail pages keep their version history
@e2e exclude The other 13 sidebars are `ncvue-w2-leaves-adoption`'s surface; the manifest unit test in `tests/vitest/manifestCaseTimeline.spec.js` asserts their tab counts are unchanged, and no journey opens them.

- **GIVEN** the `TaskDetail` page
- **WHEN** the handler opens its sidebar
- **THEN** the Version history tab SHALL still be there

### Requirement: The timeline shows writes, with reads on request (REQ-CDV-18)

You are not scrolling past your own reads to find a change. The History
tab SHALL open on writes (create, update, delete) and SHALL keep reads
behind the Action filter. Presetting the filter is a nextcloud-vue need:
`CnAuditTrailTab` holds `actionFilter` as internal state and the `audit`
sidebar widget declares no prop for it. Until it lands the tab opens
unfiltered and the Action and User filters the tab already renders SHALL
work, not sit on Loading.

#### Scenario: The Action filter narrows to updates
@e2e tests/e2e/case-timeline.spec.ts

- **GIVEN** a case with one create, one update and several reads on its trail
- **WHEN** the handler picks update in the Action filter
- **THEN** the list SHALL show the update row only
- **AND** the filter SHALL not read Loading

#### Scenario: The tab opens on writes
@e2e exclude The preset needs an `actions` (or equivalent) prop on the `audit` sidebar widget, placement section 3 row A05; the tasks.md marker tracks the block and the interim is the unfiltered tab with a working filter.

- **GIVEN** nextcloud-vue ships a preset for `actionFilter` on the `audit` sidebar widget
- **WHEN** the handler opens the History tab
- **THEN** no row with action read SHALL show until the handler widens the filter

### Requirement: The timeline can be exported (REQ-CDV-19)

You hand the history of a case to someone outside the system. The History
tab SHALL offer an Export action that downloads the rows under the current
filter as CSV with time, action, user and the changed fields. The action is
a nextcloud-vue need on `CnAuditTrailTab`; until it lands there is no
export and the tab says nothing about one.

#### Scenario: Export follows the filter
@e2e exclude `CnAuditTrailTab` has no export; placement section 3 row A05 files it against nextcloud-vue and the tasks.md marker tracks the block.

- **GIVEN** the History tab filtered to updates
- **WHEN** the handler clicks Export
- **THEN** a CSV SHALL download holding the update rows only

### Requirement: Documents, notes and mail land on the same timeline (REQ-CDV-20)

You see a document upload or a sent mail in the same list as a status
change. The History tab SHALL merge document, note and mail events for the
case with its audit rows, in one order by time. A merged feed per object
is the OpenRegister `activity` leaf, which does not exist yet:
`[blocked: openregister activity leaf]`. Interim: the audit trail over
writes only, as REQ-CDV-17 and REQ-CDV-18 describe.

#### Scenario: A document upload appears in the history
@e2e exclude The merged feed is the OpenRegister `activity` leaf (placement section 3, A05); until it ships the History tab holds audit rows only and the tasks.md marker tracks the block.

- **GIVEN** a case to which the handler uploaded bouwtekening.pdf after a status change
- **WHEN** the handler opens the History tab
- **THEN** the upload SHALL read above the status change, with the handler as actor

### Requirement: Nine tabs hold every panel of the case (REQ-CDV-16)

You reach every panel of the case from one row of nine tabs. The `case-panels`
widget on `CaseDetail` SHALL list exactly nine tabs, in the order Data
(`case-data-panel`), Files (`case-files`), Notes (`case-notes-panel`), People
(`case-people-panel`), Communication (`case-communication-panel`), Email
(`case-email-panel`), Work (`case-work-panel`), Besluiten
(`case-besluiten-panel`) and Related (`case-related-panel`). The strip SHALL
sit above the fold at 1024 pixels wide, and all nine SHALL be visible there
without a scroll or a gesture.

Objects and locations is retired. Its objects are the last section of Related,
an object linked to a case being a relation like any other, and its locations
are a map on the Data tab: `case-location` already carries latitude and
longitude, so the list was a table of coordinates nobody could picture.

A tab MAY hold more than one panel, as a `case-sections` widget whose sections
render stacked under their own headings. Files SHALL hold the case folder and
nothing else, as a files browser on the Files app's primitives, with its own
crumbs and no section heading over it (one title on the page, and the word is
files; the ZGW document list left the page on 2026-09-13, its API and register
stay); People SHALL hold the parties and the contact moments;
Work SHALL hold the tasks and the appointments; Related SHALL hold the related
cases and the sub-cases; Objects and locations SHALL hold the case objects and
the case locations.

A section SHALL be absent or non-empty, never a heading over a void.
`CnDetailWidgetHost` renders nothing, and logs nothing, for a widget type it
cannot resolve, so a section naming an unresolvable widget would leave its
heading as the only thing on screen. That is worse than the tab it replaced: as
a whole tab an unresolvable panel was merely an empty tab, and folding it under
a heading makes it look broken. Such a section SHALL withhold its heading, its
divider and its padding, so it reserves no space and reads as absent.

An empty state is content, and keeps its heading. A list that renders "no
documents yet" tells the handler the section exists and holds nothing, which is
the line of text the paragraph below is about. Only a section that renders
literally nothing goes silent.

No surface SHALL read in both the strip and the sidebar. One surface in two
places is duplication rather than coverage (REQ-CDV-17). Until 2026-09-13 that
rule was satisfied by keeping the sidebar copy and barring the strip tab; it is
satisfied the other way now, because the strip is where a handler works and the
sidebar is a shelf beside it. Notes, Email and Besluitvorming are strip tabs,
and the sidebar carries only History, Sharing and Tags, none of which has a
strip counterpart. The strip SHALL still carry no Timeline tab: the timeline is
the History sidebar tab, and that one is not moving.

A panel SHALL NOT be removed from the strip unless it is reachable elsewhere on
the page: folding it into a tab and deleting it look identical in a tab count.
The same holds for the sidebar, and it is the sharper risk in this direction,
because a sidebar tab removed without a strip tab to receive it leaves nothing
behind at all.

The strip SHALL NOT need `visibleIf` on a tab entry. That was wanted so a
collection holding nothing could be absent rather than empty; with tabs that
hold one or two collections each, an empty section is a line of text inside a
tab the handler opened deliberately.

> The ceiling has moved twice, both times deliberately. Six to seven on 2026-09-12 (Ruben): a Notes tab joined the strip beside Files. Seven to nine on 2026-09-13 (Ruben): Communication left the People tab, and Email and Besluiten left the SIDEBAR. The second move added nothing to the page. The sidebar lost exactly the three tabs the strip gained, so counted together the page holds what it held; what changed is which chrome each surface reads in. The ceiling guards UNWATCHED growth, the strip going from ten to fourteen with nothing counting it, and an exact count somebody has to edit on purpose is what does that guarding.

#### Scenario: The strip holds nine tabs and no more
@e2e tests/e2e/case-detail-kpis-and-tabs.spec.ts
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a case with three tasks and one document
- **WHEN** the handler opens the case page
- **THEN** the tab strip SHALL contain exactly nine tabs
- **AND** they SHALL read Data, Files, Notes, People, Communication, Email, Work, Besluiten, Related, in that order
- **AND** the strip SHALL carry no tab named Documents, Mail, Contacts or Objects and locations

#### Scenario: The nine tabs fit a laptop screen
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a viewport 1024 pixels wide
- **WHEN** the handler opens the case page
- **THEN** the tab strip SHALL sit above the fold
- **AND** every one of the nine tabs SHALL be visible without a scroll or a gesture

> Measured 2026-09-09 at 1024 pixels: six tabs need 661 pixels on one line, and the strip's
> tab row has roughly 280. A full-width strip yields about 570, so one line is not reachable
> at this viewport with these labels. `CnTabs` wraps rather than scrolls on purpose, because a
> scrolling strip hides tabs behind an edge with nothing to say they are there. What the
> handler needs is that no tab is clipped or off-screen, and wrapping already gives that.
>
> More tabs do not change that answer, because one line was already out of reach and the
> promise is about clipping rather than about lines. Measured 2026-09-13 in the strip's own
> font (system-ui 700 15px): "Communication" is 131 pixels of text and about 173 as a tab box,
> the second widest label after "Objects and locations" was at 212. Each extra tab costs
> roughly one more wrapped row at a narrow viewport. The 661 figure is the six-tab number and
> is left as it was taken; the assertion that matters is the e2e one, which measures every tab
> box against the viewport rather than trusting a total.

#### Scenario: Every folded panel still renders, inside the tab it moved to
@e2e tests/e2e/case-detail-kpis-and-tabs.spec.ts

- **GIVEN** a case page whose strip holds nine tabs
- **WHEN** the handler opens each tab in turn
- **THEN** each `case-sections` tab SHALL render both of its sections
- **AND** each section SHALL carry its own heading

#### Scenario: A section whose widget does not resolve stays silent
@e2e tests/e2e/case-detail-kpis-and-tabs.spec.ts

- **GIVEN** a `case-sections` tab holding a section whose widget type the registry cannot resolve
- **WHEN** the handler opens that tab
- **THEN** that section SHALL render no heading
- **AND** it SHALL reserve no vertical space and draw no divider
- **AND** every section on the tab that did resolve SHALL keep its own heading

#### Scenario: Files is the case folder and nothing else
@e2e tests/e2e/case-detail-kpis-and-tabs.spec.ts

- **GIVEN** a case page
- **WHEN** the handler opens the Files tab
- **THEN** the case folder SHALL render as a files browser, with crumbs from the user's files root down to the case folder
- **AND** no section heading and no second list SHALL render in the tab

#### Scenario: A panel that leaves the strip is still on the page
@e2e exclude The three removed panels are sidebar tabs, and each already has its own e2e coverage on the sidebar; what needs guarding is that a LATER change cannot drop one body tab without the sidebar tab existing, which is a manifest shape rather than a rendered page. Asserted in tests/vitest/caseTabConsolidation.spec.js, which pairs each removed widget id with the sidebar tab id that carries it and fails when either half is missing.

- **GIVEN** the Notes, Mail and Decisions panels are gone from the strip
- **WHEN** the manifest is read
- **THEN** the sidebar SHALL declare a notes, an email and a besluitvorming tab

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): Defines all individual panels (REQ-CM-06 through REQ-CM-13).
- **Task Management spec** (`../task-management/spec.md`): Task panel data and interactions.
- **Roles & Decisions spec** (`../roles-decisions/spec.md`): Participants and decisions panels.
- **Dashboard spec** (`../dashboard/spec.md`): App-level dashboard (different from per-case view).
- **Pipelinq**: Contactmomenten come from Pipelinq CRM integration.
- **OpenRegister**: All case data queries, including mock BRP and BAG registers for linked objects.
- **Nextcloud Files**: Document storage via `IRootFolder`.
- **@conduction/nextcloud-vue**: `CnDetailPage`, `CnDetailCard` components.

### Current Implementation Status

**Substantially implemented (MVP).** The case detail view is functional with most MVP panels in place.

**Implemented:**
- Case detail page (`src/views/cases/CaseDetail.vue`) using `CnDetailPage` from `@conduction/nextcloud-vue` with sidebar support. Bookmarkable URL: `/apps/dossiq/cases/:id`.
- Status timeline component (`src/views/cases/components/StatusTimeline.vue`) displaying ordered status dots with passed/current/future states and dates.
- Status change dropdown (`NcSelect`) with status type options from case type configuration. Result prompt shown when final status is selected (with result type dropdown or free-text fallback).
- Deadline panel (`src/views/cases/components/DeadlinePanel.vue`) showing start date, deadline, processing time, days elapsed, countdown with overdue styling, extension info (allowed/already extended), and extension request button.
- Participants section (`src/views/cases/components/ParticipantsSection.vue`) with grouped role display, add participant button, and handler assignment.
- Add participant dialog (`src/views/cases/components/AddParticipantDialog.vue`).
- Activity timeline (`src/views/cases/components/ActivityTimeline.vue`) with add note input, chronological event display.
- Result section (`src/views/cases/components/ResultSection.vue`) for recording case results.
- Quick status dropdown from case list (`src/views/cases/components/QuickStatusDropdown.vue`).
- Case creation dialog (`src/views/cases/CaseCreateDialog.vue`).
- Save/delete actions in header with validation (`validateCaseUpdate()`).
- Back navigation to case list.
- Router: `/cases/:id` route with `caseId` prop (`src/router/index.js`).
- Tasks panel with table display, status badges, priority badges, overdue highlighting, and task count.
- Extension dialog with reason field and deadline recalculation via `calculateDeadline()`.
- Activity tracking: status changes, field updates, and notes are recorded in the case's `activity` array.

**Not yet implemented:**
- REQ-CDV-04: Contactmomenten integration (Pipelinq data not yet surfaced in case view).
- REQ-CDV-05: Linked cases and objects panel (sub-cases, BAG/BRP linked objects).
- REQ-CDV-06: Document checklist panel (document types exist in schema but no checklist UI in case detail).
- REQ-CDV-07b: Responsive tablet layout (single-column stacking).
- REQ-CDV-07c: Print view with text-based status timeline.
- REQ-CDV-08: Keyboard shortcuts (N for note, T for task, S for status, D for documents, Esc, ?).
- REQ-CDV-09: Custom properties panel (property definitions are in the schema but no case-level property editor is visible).
- REQ-CDV-10c: Concurrent edit conflict detection.
- Cross-panel reactive updates (partial -- status changes update the timeline via in-memory array push, but other users' changes are not reflected without page reload).

**Mock Registers (dependency):** This spec depends on mock BRP and BAG registers being available in OpenRegister for linked object display (REQ-CDV-05b/c). These registers are available as JSON files that can be loaded on demand from `openregister/lib/Settings/`. Production deployments should connect to the actual Haal Centraal BRP API and BAG API via OpenConnector.

### Using Mock Register Data

This spec depends on the **BRP** and **BAG** mock registers for displaying linked objects on the case dashboard (REQ-CDV-05b).

**Loading the registers:**
```bash
# Load BRP register (35 persons, register slug: "brp", schema: "ingeschreven-persoon")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json

# Load BAG register (32 addresses + 21 objects + 21 buildings, register slug: "bag", schemas: "nummeraanduiding", "verblijfsobject", "pand")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/bag_register.json
```

**Test data for this spec's use cases:**
- **Linked BRP-persoon**: BSN `999993653` (Suzanne Moulin, Rotterdam) -- link as initiator/aanvrager to a case, verify display in "Gekoppelde objecten"
- **Linked BAG-object**: Use BAG nummeraanduiding records from Amsterdam (municipality code `0363`) -- link an address to a bouwvergunning case
- **Cross-reference**: BRP persons include `verblijfplaats.adresseerbaarObjectIdentificatie` linking to BAG verblijfsobject records -- verify address resolution

### Standards & References

- **CMMN 1.1**: Case detail view follows the CasePlanModel concept with visual plan item lifecycle.
- **ZGW Zaken API (VNG)**: Case data model aligns with zaak endpoints (identificatie, omschrijving, status, resultaat, zaakobjecten).
- **WCAG 2.1 AA**: Keyboard navigation, screen reader support, contrast requirements, minimum touch target size (44x44px).
- **Schema.org**: Case uses `schema:Project` typing with `schema:name`, `schema:startDate`, `schema:endDate`.
- **Nextcloud Design System**: Uses `NcButton`, `NcSelect`, `NcLoadingIcon`, `NcTextField` from `@nextcloud/vue`.
- **@conduction/nextcloud-vue**: `CnDetailPage`, `CnDetailCard` for consistent detail page layout.

### Specificity Assessment

This spec is well-specified for MVP and V1 with clear layout wireframe, panel composition, cross-panel interaction scenarios, and concrete data examples. It is implementation-ready for most requirements.

**Strengths:** ASCII wireframe layout, concrete scenarios with data, clear panel hierarchy, responsive breakpoints defined, implementation references to existing components.

**Resolved ambiguities:**
- Sidebar is used via `CnDetailPage` sidebar prop (confirmed from implementation).
- Cross-panel reactivity uses Pinia store (`useObjectStore()`) and in-memory activity array updates.
- Loading states use skeleton placeholders per panel card (REQ-CDV-01d).
- Notes are persisted via the case's `activity` array in OpenRegister (confirmed from `CaseDetail.vue` `onAddNote()`).
- Contactmomenten are fetched via cross-register query to Pipelinq's register in OpenRegister (REQ-CDV-04a).
- Print view includes all panels in a clean format with action buttons hidden (REQ-CDV-07c).
