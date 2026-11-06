# case-linked-card Specification

## Purpose
What dossiq does for case linked card. The requirements were first written by the change `case-linked-card` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-case-linked-card/`) and cover: The case page lists its links in one side card; The full profile keeps the linked card and the money panels.

## Requirements

### Requirement: The case page lists its links in one side card

The case page SHALL place a `linked` widget titled "Linked" (nl "Gekoppeld") in its side column, right after Handling,
listing the leaves `filinq-documents`, `shillinq-payment-requests-panel`, `shillinq-contracts-panel` and
`planninq-projects` in that order. It SHALL NOT place `planninq-projects` as a widget of its own.

#### Scenario: a Woo case with no fees, contract or projects

- **GIVEN** a case with no payment requests, contract or projects
- **THEN** the Gekoppeld card reads "Leges en betalingen, contract en projecten: niets gekoppeld"
- **AND** it offers "Nieuw project" and "Openen in Planninq"

### Requirement: The full profile keeps the linked card and the money panels

The full profile SHALL place the same `linked` widget in the grid cell the Projects widget had. The money placements
SHALL name the leaves shillinq registers for rendering, `shillinq-payment-requests-panel` and `shillinq-contracts-panel`.

#### Scenario: an administrator on the full structure

- **GIVEN** `menu_structure` set to the full profile
- **THEN** the case page shows the Linked card in its grid, and the fees and contract panels render
