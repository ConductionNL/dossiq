# Case Dashboard View Specification

## Purpose

The Case Dashboard View is the primary working screen for behandelaars. It combines all relevant information for a single case into one integrated view: timeline, documents, status, tasks, contactmomenten, besluiten, and linked objects. While the Case Management spec (`../case-management/spec.md`) defines the data model and individual panels (REQ-CM-06 through REQ-CM-13), this spec defines how those panels are composed into a cohesive working screen with interactions between them.

**Tender demand**: This is not a separately tendered capability but underpins the 83% (57/69) that require "zaakgericht werken." Every tender evaluation includes a demo of the case detail screen. Usability of this view is the #1 factor in user acceptance.
**Relationship to existing specs**: This spec COMPOSES elements from `case-management` (panels), `task-management` (task section), `roles-decisions` (participants, decisions), and `dashboard` (app-level overview). It adds layout, interactions, and cross-panel behaviors.
**Feature tier**: MVP (layout, panel composition, navigation), V1 (configurable layout, quick actions, keyboard shortcuts)

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
| |                           || | 15 days remaining         ||
| +---------------------------+| | Started: Jan 15           ||
|                              | | Deadline: Mar 12          ||
| +---------------------------+| +---------------------------+|
| | Documents                 || +---------------------------+|
| | 3/5 required docs         || | Participants              ||
| | - Bouwtekening [ok]       || | Handler: Jan de Vries     ||
| | - Constructie... [ok]     || | Aanvrager: Petra Jansen   ||
| +---------------------------+| +---------------------------+|
|                              | +---------------------------+|
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
+------------------------------+------------------------------+
```

## Requirements

---

### REQ-CDV-01: Integrated Case Working Screen

**Feature tier**: MVP

The system MUST provide a single integrated view that combines all case-related information and actions.

#### Scenario CDV-01a: Load case dashboard

- GIVEN case "Bouwvergunning Keizersgracht 100" (identifier "2026-042")
- WHEN the behandelaar navigates to the case (from case list, My Work, or direct URL)
- THEN the system MUST display all panels in a single scrollable view: status timeline (top), activity timeline (left), case info + deadline + participants + tasks + properties + decisions + documents (right)
- AND all data MUST load within 3 seconds (including all panel data)
- AND the URL MUST be bookmarkable: `/apps/procest/cases/2026-042`

---

### REQ-CDV-02: Cross-Panel Interactions

**Feature tier**: MVP

Actions in one panel MUST immediately reflect in other panels without requiring a page reload.

#### Scenario CDV-02a: Status change updates timeline

- GIVEN the behandelaar changes status from "Ontvangen" to "In behandeling" via the status timeline
- THEN the status timeline dots MUST update (Ontvangen filled, In behandeling highlighted)
- AND the activity timeline MUST immediately show: "Status gewijzigd naar In behandeling"
- AND if new tasks are auto-created by the status change, the tasks panel MUST update

#### Scenario CDV-02b: Document upload updates checklist

- GIVEN the behandelaar uploads a document "Welstandsadvies" via the documents panel
- THEN the documents checklist MUST update: "Welstandsadvies" changes from missing to present (checkmark)
- AND the completion count MUST update: "4/5 complete"
- AND the activity timeline MUST show: "Document 'Welstandsadvies' toegevoegd"

#### Scenario CDV-02c: Task completion updates progress

- GIVEN the behandelaar completes task "Review documenten" via the tasks panel
- THEN the task MUST show a checkmark and move to completed state
- AND the task count MUST update: "4/5"
- AND the activity timeline MUST show: "Taak 'Review documenten' afgerond"

---

### REQ-CDV-03: Quick Actions

**Feature tier**: MVP

The case dashboard MUST provide quick actions for the most common operations without opening modal dialogs.

#### Scenario CDV-03a: Quick status change

- GIVEN the case dashboard is open
- WHEN the behandelaar clicks the current status in the timeline
- THEN a dropdown MUST appear with available next statuses
- AND selecting a status MUST update immediately (inline, no modal)

#### Scenario CDV-03b: Quick note addition

- GIVEN the activity timeline panel
- WHEN the behandelaar types in the "Add note" input and presses Enter
- THEN the note MUST be saved and appear at the top of the timeline
- AND the input MUST clear for the next note

#### Scenario CDV-03c: Quick task creation

- GIVEN the tasks panel
- WHEN the behandelaar clicks "+" and types a task title
- THEN a task MUST be created linked to the case with status "available"
- AND the task MUST appear in the tasks panel immediately

---

### REQ-CDV-04: Contactmomenten Integration

**Feature tier**: V1

The case dashboard MUST display contactmomenten (contact moments) linked to the case, showing all interactions with the initiator/aanvrager.

#### Scenario CDV-04a: Display contactmomenten

- GIVEN a case with 3 contactmomenten from Pipelinq:
  - Mar 1: Telefoon -- "Vraag over status aanvraag" (KCC medewerker: Anouk)
  - Feb 15: E-mail -- "Aanvullende documenten verstuurd" (Petra Jansen)
  - Jan 16: Balie -- "Aanvraag ingediend" (Petra Jansen)
- WHEN the behandelaar views the case dashboard
- THEN the contactmomenten MUST appear in the activity timeline, interleaved with other events by date
- AND each contactmoment MUST show: kanaal (telefoon/e-mail/balie), samenvatting, medewerker, datum
- AND the behandelaar MUST be able to click through to the full contactmoment in Pipelinq

---

### REQ-CDV-05: Linked Cases and Objects

**Feature tier**: V1

The case dashboard MUST display linked cases (parent/child, related) and linked objects.

#### Scenario CDV-05a: Display sub-cases

- GIVEN a parent case "Bouwproject Centrum" with 2 sub-cases
- WHEN the behandelaar views the case dashboard
- THEN a "Gerelateerde zaken" section MUST show the sub-cases with: title, status, deadline
- AND each sub-case MUST be clickable to navigate to its own case dashboard

#### Scenario CDV-05b: Display linked objects

- GIVEN a case linked to a BAG-object (Keizersgracht 100) and a BRP-persoon (Petra Jansen)
- WHEN the behandelaar views the case dashboard
- THEN the linked objects MUST be displayed with: type, identifier, description
- AND each object MUST be clickable to view its details

---

### REQ-CDV-06: Responsive Layout

**Feature tier**: MVP

The case dashboard MUST be usable on different screen sizes.

#### Scenario CDV-06a: Desktop layout (>1200px)

- GIVEN a desktop screen with width 1440px
- THEN the layout MUST use the two-column layout (60/40 split) as shown in the wireframe

#### Scenario CDV-06b: Tablet layout (768-1200px)

- GIVEN a tablet screen with width 1024px
- THEN the layout MUST stack panels in a single column: status timeline, case info, deadline, activity timeline, tasks, documents, participants, properties, decisions

#### Scenario CDV-06c: Print view

- GIVEN the behandelaar pressing Ctrl+P on the case dashboard
- THEN the print layout MUST show all case information in a clean, printable format
- AND the status timeline MUST be rendered as a text list (not interactive dots)

---

### REQ-CDV-07: Keyboard Navigation

**Feature tier**: V1

The case dashboard SHOULD support keyboard shortcuts for power users.

#### Scenario CDV-07a: Keyboard shortcuts

- GIVEN the case dashboard is focused
- THEN the following shortcuts MUST work:
  - `N` -- focus the "Add note" input
  - `T` -- focus the "Add task" input
  - `S` -- open the status change dropdown
  - `D` -- open the document upload dialog
  - `Esc` -- close any open dropdown or dialog
  - `?` -- show keyboard shortcut help overlay

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): Defines all individual panels (REQ-CM-06 through REQ-CM-13).
- **Task Management spec** (`../task-management/spec.md`): Task panel data and interactions.
- **Roles & Decisions spec** (`../roles-decisions/spec.md`): Participants and decisions panels.
- **Dashboard spec** (`../dashboard/spec.md`): App-level dashboard (different from per-case view).
- **Pipelinq**: Contactmomenten come from Pipelinq CRM integration.
- **OpenRegister**: All case data queries.
