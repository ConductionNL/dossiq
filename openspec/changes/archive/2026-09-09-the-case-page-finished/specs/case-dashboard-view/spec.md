## REMOVED Requirements

### Requirement: The tab strip fits one row and reads in work order (REQ-CDV-16)

**Reason:** the case page went from nine tabs to six, so the requirement this
replaces no longer describes the strip. Its three scenarios assert a five-tab
work order with Sub-cases, Locations, Appointments and Decisions trailing it,
and a tab hidden when its collection is empty. All three are false after the
consolidation: those four panels are now sections inside Related and Objects
and locations, and a section holding nothing renders a line of text inside a
tab the handler opened on purpose, which is why `visibleIf` is no longer
wanted. Carrying the scenarios forward would leave the spec asserting a strip
that is not on the page. Written as a removal plus an addition rather than a
rewrite in place so the three dropped scenarios are dropped on the record.

**Migration:** none. The requirement below carries the same REQ-CDV-16 id and
covers the same ground for the six-tab strip.

## ADDED Requirements

### Requirement: Six tabs hold every panel of the case (REQ-CDV-16)

You reach every panel of the case from one row of six tabs. The `case-panels`
widget on `CaseDetail` SHALL list exactly six tabs, in the order Data
(`case-core`), Documents (`case-documents-panel`), People
(`case-people-panel`), Work (`case-work-panel`), Related
(`case-related-panel`) and Objects and locations (`case-objects-panel`). The
strip SHALL sit above the fold at 1024 pixels wide, and all six SHALL be
visible there without a scroll or a gesture.

A tab MAY hold more than one panel, as a `case-sections` widget whose sections
render stacked under their own headings. Documents SHALL hold the dossier list
and the case folder; People SHALL hold the parties and the contact moments;
Work SHALL hold the tasks and the appointments; Related SHALL hold the related
cases and the sub-cases; Objects and locations SHALL hold the case objects and
the case locations.

The strip SHALL carry no Notes, Mail, Decisions or Timeline tab. Each of those
duplicates a sidebar tab on the same page, and one surface in two places is
duplication rather than coverage (REQ-CDV-17). A panel SHALL NOT be removed
from the strip unless it is reachable elsewhere on the page: folding it into a
tab and deleting it look identical in a tab count.

The strip SHALL NOT need `visibleIf` on a tab entry. That was wanted so a
collection holding nothing could be absent rather than empty; with six tabs
each holding two collections, an empty section is a line of text inside a tab
the handler opened deliberately.

#### Scenario: The strip holds six tabs and no more
@e2e tests/e2e/case-detail-kpis-and-tabs.spec.ts
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a case with three tasks and one document
- **WHEN** the handler opens the case page
- **THEN** the tab strip SHALL contain exactly six tabs
- **AND** they SHALL read Data, Documents, People, Work, Related, Objects and locations, in that order
- **AND** the strip SHALL carry no tab named Files, Notes, Mail or Decisions

#### Scenario: The six tabs fit a laptop screen
@e2e tests/e2e/case-header.spec.ts

- **GIVEN** a viewport 1024 pixels wide
- **WHEN** the handler opens the case page
- **THEN** the tab strip SHALL sit above the fold
- **AND** every one of the six tabs SHALL be visible without a scroll or a gesture

> Measured 2026-09-09 at 1024 pixels: six tabs need 661 pixels on one line, and the strip's
> tab row has roughly 280. A full-width strip yields about 570, so one line is not reachable
> at this viewport with these labels. `CnTabs` wraps rather than scrolls on purpose, because a
> scrolling strip hides tabs behind an edge with nothing to say they are there. What the
> handler needs is that no tab is clipped or off-screen, and wrapping already gives that.

#### Scenario: Every folded panel still renders, inside the tab it moved to
@e2e tests/e2e/case-detail-kpis-and-tabs.spec.ts

- **GIVEN** a case page whose strip holds six tabs
- **WHEN** the handler opens each tab in turn
- **THEN** each `case-sections` tab SHALL render both of its sections
- **AND** each section SHALL carry its own heading

#### Scenario: Files keeps its share and comment surface
@e2e tests/e2e/case-documents.spec.ts
@e2e tests/e2e/case-detail-kpis-and-tabs.spec.ts

- **GIVEN** a case page
- **WHEN** the handler opens the Documents tab
- **THEN** the dossier list SHALL render first
- **AND** the case folder SHALL render under it, as a section rather than a tab

#### Scenario: A panel that leaves the strip is still on the page
@e2e exclude The three removed panels are sidebar tabs, and each already has its own e2e coverage on the sidebar; what needs guarding is that a LATER change cannot drop one body tab without the sidebar tab existing, which is a manifest shape rather than a rendered page. Asserted in tests/vitest/caseTabConsolidation.spec.js, which pairs each removed widget id with the sidebar tab id that carries it and fails when either half is missing.

- **GIVEN** the Notes, Mail and Decisions panels are gone from the strip
- **WHEN** the manifest is read
- **THEN** the sidebar SHALL declare a notes, an email and a besluitvorming tab
