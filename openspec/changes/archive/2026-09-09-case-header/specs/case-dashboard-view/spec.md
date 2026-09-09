## ADDED Requirements

### Requirement: The case names itself in the header (REQ-CDV-14)

You see which case you are on without reading the data widget. `CaseDetail`
SHALL set `config.subtitleField` to `identifier`, so the case number reads
under the title. The page SHALL render a header row widget `case-header`
on the first layout row that shows a `CnStatusBadge` with the name of the
case's status type and the deadline countdown (days left, or days overdue
in the danger variant), replacing the Time left tile. A case without a
status record SHALL show the badge as Unknown rather than nothing; a case
without a deadline SHALL show no countdown. The subtitle line built from
identifier, case type and assignee together is a nextcloud-vue need
(`CnDetailPage` has `subtitleField` only); until it lands the identifier
alone is the subtitle.

#### Scenario: The number reads under the title
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a case with identifier 2026-0015 and title Aanbouw Beethovenlaan 8
- **WHEN** the handler opens the case page
- **THEN** the page title SHALL read Aanbouw Beethovenlaan 8
- **AND** the subtitle SHALL read 2026-0015

#### Scenario: Status and deadline sit in the header row
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a case in status In behandeling with a deadline 26 days ago
- **WHEN** the handler opens the case page
- **THEN** the header row SHALL show a status badge reading In behandeling
- **AND** the header row SHALL show 26 days overdue in the danger variant
- **AND** no tile labelled Time left SHALL render in the KPI row

#### Scenario: A case without a status or a deadline still has a header
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a case with no status record and no deadline
- **WHEN** the handler opens the case page
- **THEN** the status badge SHALL read Unknown
- **AND** the header row SHALL show no countdown

#### Scenario: The full subtitle line waits on nextcloud-vue
@e2e exclude The templated subtitle (identifier, case type, assignee) needs a `subtitle` template on `CnDetailPage`, which 2.40.0 does not have; the manifest unit test asserts the interim `subtitleField` and the tasks.md marker tracks the block.

- **GIVEN** nextcloud-vue ships a templated `subtitle` on the detail page
- **WHEN** `CaseDetail` sets it to identifier, case type and assignee
- **THEN** the subtitle SHALL read 2026-0015, Omgevingsvergunning, Jan de Vries

### Requirement: A breadcrumb leads back to the list (REQ-CDV-15)

You go back to the case list from the case without the menu. `CaseDetail`
SHALL declare `breadcrumbs` with Cases (route `Cases`) followed by the case
title, and the page SHALL render them through `CnBreadcrumbs` above the
title. The last crumb SHALL be the current page and SHALL not be a link.

#### Scenario: Cases is one click away
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** the handler is on a case page
- **WHEN** they click Cases in the breadcrumb
- **THEN** the Cases page SHALL open
- **AND** the case list SHALL keep the lens it had before

#### Scenario: The current crumb is not a link
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a case titled Aanbouw Beethovenlaan 8
- **WHEN** the handler opens the case page
- **THEN** the breadcrumb SHALL read Cases, Aanbouw Beethovenlaan 8
- **AND** the last crumb SHALL have `aria-current="page"` and no `href`

### Requirement: The tab strip fits one row and reads in work order (REQ-CDV-16)

You reach documents, parties and tasks in one row of tabs. The
`case-panels` widget on `CaseDetail` SHALL list its tabs in the order Data
(`case-core`), Documents (`case-documents`), Parties (`case-roles`), Tasks
(`case-tasks`) and Communication (`case-communication`), and only then
Sub-cases (`case-sub-cases`), Locations (`case-locaties`), Appointments
(`case-calendar`) and Decisions (`case-decidesk-decisions`). The strip
SHALL carry no Timeline tab: the case timeline is the sidebar History tab
(REQ-CDV-17), and a body panel over the same log would put one history in
two places. A tab whose sibling change has not landed yet SHALL be absent
from the strip, not present and empty. The first five tabs SHALL fit on one
line at 1024 pixels wide. Sub-cases, Locations,
Appointments and Decisions SHALL show only when they hold at least one
row; that needs `visibleIf` on a tab entry, which `CnTabsWidget` does not
read yet, so until it lands the four stay last in the strip.

#### Scenario: The five work tabs come first, in order
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a case with three tasks and one document
- **WHEN** the handler opens the case page
- **THEN** the tab strip SHALL start with Data, Documents, Parties, Tasks, Communication in that order
- **AND** Sub-cases, Locations, Appointments and Decisions SHALL come after them

#### Scenario: The work tabs fit a laptop screen
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a viewport 1024 pixels wide
- **WHEN** the handler opens the case page
- **THEN** the first five tabs SHALL share one line
- **AND** the tab strip SHALL sit above the fold

#### Scenario: A tab with nothing in it is absent
@e2e exclude Hiding a tab on its row count needs `visibleIf` on a `CnTabsWidget` tab entry (placement section 3, A33); the manifest unit test asserts that the four conditional tabs are last, and the tasks.md marker tracks the block.

- **GIVEN** a case with no sub-cases, no locations, no appointments and no decisions
- **WHEN** the handler opens the case page
- **THEN** the strip SHALL show Data, Documents, Parties, Tasks, Communication and nothing else
