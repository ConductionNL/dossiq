# case-dashboard-view delta: case-page-dutch-labels-one-history-tour-first

## ADDED Requirements

### Requirement: The case page names every tab once (REQ-CDV-23)

The case page's tab strip and its sidebar SHALL NOT carry two tabs with the same
id or the same label in the reader's language. The history of the case is the
tab in the strip; the sidebar tab over the audit trail SHALL be called
"Audit trail" ("Audittrail"), as DqZaakHistorie points to it.

#### Scenario: One Geschiedenis tab
@e2e exclude {asserted for both structure profiles in tests/vitest/casePageLabelsHistoryAndTourFirst.spec.js and checked live on the tab list}

- **GIVEN** a reader whose language is Dutch
- **WHEN** they open a case
- **THEN** exactly one tab SHALL read "Geschiedenis"
- **AND** the sidebar SHALL offer the audit trail as "Audittrail"
