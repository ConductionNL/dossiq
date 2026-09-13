## ADDED Requirements

### Requirement: The case page subscribes to its object and its runs

`#CaseDetail` SHALL install `liveUpdatesPlugin` for the viewed case and
for runs whose subject is the case, refreshing through the store's fetch
on each event. No widget on `#CaseDetail` SHALL poll.

#### Scenario: A colleague's change appears
@e2e tests/e2e/case-detail-kpis-and-tabs.spec.ts

- **GIVEN** you have a case open
- **WHEN** a colleague changes its status in another session
- **THEN** the header SHALL show the new status without a reload

#### Scenario: No poll on the page
@e2e exclude structural; vitest over the manifest asserts no `pollSeconds` on `#CaseDetail`

- **GIVEN** the manifest
- **WHEN** `#CaseDetail`'s widgets are read
- **THEN** none SHALL carry `pollSeconds`
