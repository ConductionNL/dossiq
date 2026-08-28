## ADDED Requirements

### Requirement: The voorstellen route renders a bespoke index

`/voorstellen` SHALL render a purpose-built list for B&W voorstellen
rather than the shared generic index shell. The view SHALL carry its own
title and a control that starts a new voorstel.

#### Scenario: The index announces itself and offers creation

- **WHEN** an authorised user opens `/voorstellen`
- **THEN** the page renders a heading naming the B&W voorstellen list
- **AND** a control to create a new voorstel is present and enabled

### Requirement: The list is filterable by lifecycle state

The index SHALL offer filters over the voorstellen lifecycle, at minimum
separating those still in progress from those concluded, plus an
unfiltered view.

#### Scenario: Filtering narrows the list

- **WHEN** the user selects the in-progress filter
- **THEN** the list shows only voorstellen in that state
- **AND** selecting the unfiltered view restores the full list

### Requirement: An empty list explains itself in Dutch

When a filter matches nothing, the index SHALL render a Dutch empty state
naming what is absent — not the shared index's generic no-results text,
and never an error.

#### Scenario: The empty state is specific and Dutch

- **WHEN** the in-progress filter matches no voorstellen
- **THEN** a Dutch empty state states that there are no active voorstellen
- **AND** no error is surfaced
