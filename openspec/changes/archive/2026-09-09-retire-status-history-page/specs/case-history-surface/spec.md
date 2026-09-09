# case-history-surface

## ADDED Requirements

### Requirement: Change history is a case-detail sidebar tab, not a standalone page

A case's status/change history SHALL be presented as a sidebar tab on the `CaseDetail` page (an `audit-trail` tab titled "Change history"), and SHALL NOT be exposed as a standalone top-level page or menu item. The app SHALL NOT declare a `StatusRecords` page or `StatusRecordsMenu` navigation entry.

#### Scenario: Case detail exposes change history in context

- **GIVEN** a user opens a case's detail page
- **THEN** a "Change history" sidebar tab (type `audit-trail`) shows that case's status/change records
- **AND** no standalone "Status history" page or menu item exists in the app navigation

#### Scenario: Retired page is not routable from the menu

- **GIVEN** the app navigation
- **THEN** there is no `StatusRecordsMenu` entry and no relocation of it into the Reports group
- **AND** the `StatusRecords` page is absent from the manifest
