# case-documents-merge-via-filinq-leaf Specification

## Purpose
A handler merges a case's documents into one PDF from the case page. Filinq owns the merge and registers the leaf; dossiq only places it on the case page, so the panel appears when filinq is installed and is absent otherwise.

## Requirements

### Requirement: The case page places filinq's merge-to-PDF leaf (REQ-CDM-001)
The case detail page MUST place filinq's `filinq-merge-to-pdf` leaf as a grid
panel with its own layout cell, MUST NOT gate it on `requiredApp`, and MUST
NOT merge documents itself.

@e2e exclude The panel's content is filinq's leaf, registered only when filinq with ConductionNL/filinq#1251 is installed; a dossiq-only e2e run cannot mount it. The dossiq side is a manifest placement, asserted in tests/vitest/siblingLeavesOnTheCase.spec.js.

#### Scenario: A handler merges a case's documents into one PDF
- **GIVEN** filinq is installed and a case holds three documents
- **WHEN** the handler opens the case, chooses two documents in the merge panel and orders them
- **THEN** filinq MUST write one PDF of those two, in that order, in the case folder

#### Scenario: Without filinq the panel is absent
- **GIVEN** filinq is not installed
- **WHEN** a handler opens a case
- **THEN** no merge panel MUST be shown and the page MUST NOT error
