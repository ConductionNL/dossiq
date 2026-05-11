---
status: implemented
---
# case-dashboard-view Specification

## Purpose
Deliver a polished, responsive, and accessible case detail dashboard that surfaces all relevant case context (status, deadlines, activity, tasks, documents, participants) on a single screen. This change closes the remaining MVP gaps in the existing `case-dashboard-view` capability: tablet/print rendering, per-panel skeleton loading, and a graceful 404 state for missing cases.

## Context
`CaseDetail.vue` already renders a two-column dashboard composed of panel cards (status timeline, info, deadline, activity, tasks, documents, participants). Tender demos and on-call inspections require the same screen to work on tablets, print cleanly, and signal loading state per-card instead of via a single page-level spinner. The capability is implemented end-to-end in code; this spec documents the contract.

## Requirements

### REQ-CDV-01c: Case Not Found State
The dashboard MUST display a localized 404 empty state when the requested case cannot be loaded.

#### Scenario CDV-01c-1: Unknown case identifier
- GIVEN a user navigates to `/cases/does-not-exist`
- WHEN the fetch resolves with no case object
- THEN the view MUST render an `NcEmptyContent` with title "Zaak niet gevonden"
- AND a "Terug naar overzicht" button MUST be visible
- AND clicking the button MUST navigate the user to the case list view

#### Scenario CDV-01c-2: Case fetch error
- GIVEN the backend returns an error while loading a case
- THEN the empty state MUST be shown instead of a broken panel layout
- AND the action buttons in the page header MUST be hidden

### REQ-CDV-01d: Per-Panel Skeleton Loading
The dashboard MUST render a skeleton placeholder for each panel card while case data is loading.

#### Scenario CDV-01d-1: Initial render with no data
- GIVEN a user opens a case detail page for the first time
- WHEN the case data has not yet resolved
- THEN each panel card (status timeline, info, deadline, activity, tasks, documents, participants) MUST render a skeleton placeholder with animated bars
- AND no global page-level spinner MUST be shown

#### Scenario CDV-01d-2: Skeleton replaced on data ready
- GIVEN the skeleton placeholders are visible
- WHEN the case data resolves
- THEN each skeleton MUST be replaced by its real panel content in place
- AND the page MUST NOT flicker or fully re-mount

### REQ-CDV-07b: Tablet Layout
The dashboard MUST adapt to a single-column layout at tablet viewport widths.

#### Scenario CDV-07b-1: Viewport at or below 1200px
- GIVEN a user views the dashboard on a viewport of 1200px or narrower
- THEN the panels MUST stack vertically in a single column
- AND the order MUST be: status timeline, case info, deadline, activity, tasks, documents, participants
- AND no horizontal scrollbar MUST appear on the page body

#### Scenario CDV-07b-2: Touch target sizing
- GIVEN the tablet layout is active
- THEN all interactive controls (buttons, dropdowns, selects, status pills) MUST meet a minimum 44x44 CSS pixel touch target
- AND focus rings MUST remain visible

### REQ-CDV-07c: Print View
The dashboard MUST render a clean, printable representation when the user prints the page.

#### Scenario CDV-07c-1: Print stylesheet applied
- GIVEN a user invokes the browser print dialog (e.g., Ctrl+P)
- THEN `@media print` rules MUST hide navigation, action buttons, save/delete controls, and interactive dropdowns
- AND backgrounds MUST be forced to white and shadows removed
- AND the status timeline MUST render as a textual list (not interactive dots)

#### Scenario CDV-07c-2: Print header includes identifier and date
- GIVEN the dashboard is printed
- THEN the printed output MUST include a header containing the case identifier and the current print date

### REQ-CDV-DASH-01: Panel Composition
The dashboard MUST present case data through a fixed set of panel cards.

#### Scenario CDV-DASH-01-1: Default desktop layout
- GIVEN a user opens a case detail page on a viewport wider than 1200px
- THEN the dashboard MUST render a two-column layout with a primary column (status timeline, activity, documents) and a secondary column (case info, deadline, tasks, participants)
- AND each panel MUST be rendered as a `CnDetailCard` (or equivalent panel component) with a clear title

#### Scenario CDV-DASH-01-2: Empty panel handling
- GIVEN a case has no tasks, documents, or participants
- THEN each affected panel MUST render an inline empty state with a short Dutch label (e.g., "Geen taken", "Geen documenten", "Geen betrokkenen")
- AND the panel MUST NOT be hidden entirely

### REQ-CDV-DASH-02: Header Actions Visibility
Page-level header actions MUST adapt to the dashboard's loading and error states.

#### Scenario CDV-DASH-02-1: Actions during loading
- GIVEN the dashboard is in skeleton loading state
- THEN primary header actions (save, status change, delete) MUST be disabled or hidden
- AND the back link MUST remain available

#### Scenario CDV-DASH-02-2: Actions in not-found state
- GIVEN the dashboard is in the "Zaak niet gevonden" state
- THEN all header actions related to the case MUST be hidden
- AND only the "Terug naar overzicht" navigation MUST be available

## Dependencies
- `CaseDetail.vue` (Procest) -- host view that composes the panels
- `@nextcloud/vue` `NcEmptyContent`, `NcLoadingIcon` -- empty/loading primitives
- `CnDetailCard` from `@conduction/nextcloud-vue` -- panel container
- Case data store (object store + filesPlugin) -- supplies case, activity, tasks, documents, participants
