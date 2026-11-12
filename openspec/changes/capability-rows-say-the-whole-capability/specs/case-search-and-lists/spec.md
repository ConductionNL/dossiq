# case-search-and-lists

## ADDED Requirements

### Requirement: A handler saves a search as a view of their own (REQ-CRS-08)

A handler SHALL be able to save the current filters, search term and sort of the Cases page as a named view. The view SHALL be offered to that handler only, and applying it SHALL restore the list as it was saved.

Rows: `9.3` (dossiq matrix).

#### Scenario: A handler saves and reapplies a view
- **GIVEN** a handler who filtered the Cases page to their open permits
- **WHEN** they save it as "My permits" and later apply it
- **THEN** the list shows the same filters and only they see the view
