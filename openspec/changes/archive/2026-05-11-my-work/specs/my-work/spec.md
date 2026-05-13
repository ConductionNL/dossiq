---
status: implemented
---
# my-work Specification

## Purpose
Document the polish layer applied to the existing "Mijn werk" view in Procest: case-type name display on case items, ARIA labels and keyboard navigation for WCAG AA conformance, and a responsive layout for narrow viewports. The personal workload aggregation and filtering surface itself was shipped earlier and is captured in the canonical `my-work` capability spec; this change formalizes the non-functional requirements applied on top.

## Context
`MyWork.vue` renders a per-user list of cases and tasks grouped by urgency (overdue, today, this week, later). Items previously showed only entity type and title, with limited keyboard support. This change resolves case type names at mount, attaches ARIA semantics to tabs and items, exposes keyboard activation, and stacks rows on narrow viewports.

## ADDED Requirements
### Requirement: REQ-MYWORK-CT-01 — Case Type Name on Case Items
Case items in the "Mijn werk" view MUST display the case type name as a subtitle.

#### Scenario: MYWORK-CT-01-1: Case type resolved from cached collection
- **GIVEN** a user opens "Mijn werk"
- **WHEN** the view mounts
- **THEN** `objectStore.fetchCollection('caseType')` MUST be invoked once to build a lookup map from case type id to title
- **AND** each case item MUST display the resolved case type title (e.g., "Omgevingsvergunning") below its title

#### Scenario: MYWORK-CT-01-2: Unknown or missing case type
- **GIVEN** a case item references a case type id not present in the lookup
- **THEN** the subtitle MUST render an empty placeholder (no broken label, no console error)

#### Scenario: MYWORK-CT-01-3: Task items unaffected
- **GIVEN** a mix of case and task items
- **THEN** only case items MUST show the case-type subtitle
- **AND** task items MUST continue to show their existing subtitle (e.g., linked case reference)

### Requirement: REQ-MYWORK-A11Y-01 — ARIA Semantics
The "Mijn werk" view MUST expose ARIA semantics that allow screen readers to announce structure and state.

#### Scenario: MYWORK-A11Y-01-1: Filter tabs use tab pattern
- **GIVEN** the filter bar at the top of the view
- **THEN** the container MUST have `role="tablist"`
- **AND** each filter button MUST have `role="tab"` with `aria-selected` reflecting the active state

#### Scenario: MYWORK-A11Y-01-2: Section headers expose heading role
- **GIVEN** grouped sections "Overdue", "Today", "This week", "Later"
- **THEN** each section header MUST have `role="heading"` with an appropriate `aria-level`

#### Scenario: MYWORK-A11Y-01-3: Item aria-label
- **GIVEN** a case item with title "Bouwvergunning Kerkstraat" that is overdue
- **THEN** the item MUST have an `aria-label` announcing entity type, title, and urgency status (e.g., "Zaak: Bouwvergunning Kerkstraat, te laat")

#### Scenario: MYWORK-A11Y-01-4: Live region for overdue
- **GIVEN** urgency text that changes (e.g., when the day rolls over)
- **THEN** the overdue indicator MUST be in an `aria-live="polite"` region so updates are announced without stealing focus

### Requirement: REQ-MYWORK-KB-01 — Keyboard Navigation
The "Mijn werk" view MUST be fully operable from the keyboard.

#### Scenario: MYWORK-KB-01-1: Tab to items
- **GIVEN** the view is rendered
- **THEN** every item row MUST have `tabindex="0"`
- **AND** pressing Tab MUST move focus through items in their visual order

#### Scenario: MYWORK-KB-01-2: Activate item with Enter/Space
- **GIVEN** an item row has keyboard focus
- **WHEN** the user presses Enter or Space
- **THEN** the same navigation MUST occur as a mouse click on the item

#### Scenario: MYWORK-KB-01-3: Activate filter tab with Enter/Space
- **GIVEN** a filter tab has keyboard focus
- **WHEN** the user presses Enter or Space
- **THEN** the corresponding filter MUST become active and `aria-selected` MUST update

### Requirement: REQ-MYWORK-RWD-01 — Responsive Layout
The "Mijn werk" view MUST remain readable on narrow viewports.

#### Scenario: MYWORK-RWD-01-1: Stack rows at 768px or below
- **GIVEN** a viewport of 768 CSS pixels or narrower
- **THEN** item rows MUST stack their content vertically (title on top, priority and deadline below)
- **AND** padding MUST reduce to remain comfortable on mobile

#### Scenario: MYWORK-RWD-01-2: Filter tabs remain operable
- **GIVEN** the mobile layout is active
- **THEN** filter tabs MUST remain visible and tappable
- **AND** each tap target MUST be at least 44x44 CSS pixels

### Requirement: REQ-MYWORK-FOCUS-01 — Focus Management
The "Mijn werk" view MUST manage focus predictably when items, filters, or sections change.

#### Scenario: MYWORK-FOCUS-01-1: Focus preserved across filter change
- **GIVEN** a filter tab has keyboard focus
- **WHEN** the user activates a different filter
- **THEN** focus MUST remain on the newly active filter tab
- **AND** the focus ring MUST be visible

#### Scenario: MYWORK-FOCUS-01-2: Focus visible on item
- **GIVEN** an item row receives keyboard focus
- **THEN** the row MUST render a clearly visible focus indicator that meets WCAG AA contrast requirements
- **AND** the indicator MUST NOT rely on colour alone

#### Scenario: MYWORK-FOCUS-01-3: Skip duplicate focusables
- **GIVEN** an item that contains nested interactive controls (e.g., a quick-action button)
- **THEN** the outer row MUST be focusable as a single stop
- **AND** tabbing into the row MUST NOT trap the user in repeated nested stops

### Requirement: REQ-MYWORK-INT-01 — Backwards-Compatible Integration
The polish layer MUST NOT regress existing "Mijn werk" behaviour.

#### Scenario: MYWORK-INT-01-1: Existing sections preserved
- **GIVEN** the existing grouped sections, filters, and counts
- **WHEN** this change is applied
- **THEN** those affordances MUST continue to work unchanged
- **AND** no new dependencies on additional stores or APIs MUST be introduced beyond the existing object store

#### Scenario: MYWORK-INT-01-2: Single fetch on mount
- **GIVEN** the case-type lookup is built on mount
- **THEN** the fetch MUST occur at most once per view mount
- **AND** subsequent renders MUST reuse the cached lookup

## Dependencies
- `src/views/MyWork.vue`
- Shared object store (`createObjectStore('caseType')`)
- Existing canonical `my-work` capability spec (`openspec/specs/my-work/spec.md`) for the underlying workload, filter, sort, and grouping behaviour
