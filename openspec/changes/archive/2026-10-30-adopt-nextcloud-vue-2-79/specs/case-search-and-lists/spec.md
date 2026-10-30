## ADDED Requirements

### Requirement: The Cases list filters from its column headers (REQ-CSL-07)

The Cases list in the simple structure SHALL show a filter funnel in the
header of each of its columns, as the DqZaken board draws them on Zaak, Type,
Status, Behandelaar and Termijn. A header filter SHALL write into the same
active filters as the filter panel, so the list, its count and the panel
agree.

#### Scenario: Every column of the Cases list has a funnel
@e2e exclude The page and column configuration is asserted in tests/vitest/simpleListAndDashboard.spec.js; the funnel itself is the library's CnIndexPage, covered by nextcloud-vue's own tests.

- **GIVEN** the simple structure is on
- **WHEN** a handler opens the Cases list
- **THEN** the Case, Type, Status, Handler and Deadline columns SHALL each show a filter funnel in the header
- **AND** neither the page nor any of those columns SHALL opt out of header filters
