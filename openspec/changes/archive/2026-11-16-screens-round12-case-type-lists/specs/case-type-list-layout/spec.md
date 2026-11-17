## ADDED Requirements

### Requirement: A case type carries the look of its own list (REQ-CTLL-2)

`caseType.x-index` SHALL accept, beside `columns`, `defaultSort` and
`searchFields`, the keys `title`, `countText`, `addLabel`, `footerNote`,
`breadcrumb`, `headerMeta`, `quickFilters` and `afterList`. While the Cases list
is narrowed to that case type it SHALL show them in place of the page's own.

#### Scenario: The complaints list reads as Complaints
@e2e exclude measured against board DqKlachten by the round 12 pixel check, not an e2e flow

- **GIVEN** the seeded complaint case type with its `x-index`
- **WHEN** you open the Cases list narrowed to that case type
- **THEN** the heading SHALL read Complaints and the add button Register a complaint
