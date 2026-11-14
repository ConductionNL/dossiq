## ADDED Requirements

### Requirement: The case keeps one history (REQ-CDV-17)

You read what happened on the case in one list, newest first, with who did
it. The `CaseDetail` sidebar SHALL carry exactly one history tab: the
`audit` tab labelled History, rendering `CnAuditTrailTab` over the case's
OpenRegister audit trail. The `version-history` tab
(`VersionHistoryLeafTab`) SHALL be absent from the `CaseDetail` sidebar.
Other detail pages keep their version history tab; this requirement
covers the case page only. Each row SHALL show the action, the user and
the time, and the list SHALL order newest first.

#### Scenario: One history tab in the sidebar
@e2e tests/e2e/case-timeline.spec.ts

- **GIVEN** a case with identifier 2026-0015
- **WHEN** the handler opens the case page and opens the sidebar
- **THEN** the sidebar SHALL offer a tab with id `audit`
- **AND** no tab with id `version-history` SHALL be present

#### Scenario: The newest write reads first, with its actor
@e2e tests/e2e/case-timeline.spec.ts

- **GIVEN** a case whose description admin changed one minute ago
- **WHEN** the handler opens the History tab
- **THEN** the first row SHALL read action update, user admin
- **AND** the row that created the case SHALL sit below it

#### Scenario: Other detail pages keep their version history
@e2e exclude The other 13 sidebars are `ncvue-w2-leaves-adoption`'s surface; the manifest unit test in `tests/vitest/manifestCaseTimeline.spec.js` asserts their tab counts are unchanged, and no journey opens them.

- **GIVEN** the `TaskDetail` page
- **WHEN** the handler opens its sidebar
- **THEN** the Version history tab SHALL still be there

### Requirement: The timeline shows writes, with reads on request (REQ-CDV-18)

You are not scrolling past your own reads to find a change. The History
tab SHALL open on writes (create, update, delete) and SHALL keep reads
behind the Action filter. Presetting the filter is a nextcloud-vue need:
`CnAuditTrailTab` holds `actionFilter` as internal state and the `audit`
sidebar widget declares no prop for it. Until it lands the tab opens
unfiltered and the Action and User filters the tab already renders SHALL
work, not sit on Loading.

#### Scenario: The Action filter narrows to updates
@e2e tests/e2e/case-timeline.spec.ts

- **GIVEN** a case with one create, one update and several reads on its trail
- **WHEN** the handler picks update in the Action filter
- **THEN** the list SHALL show the update row only
- **AND** the filter SHALL not read Loading

#### Scenario: The tab opens on writes
@e2e exclude The preset needs an `actions` (or equivalent) prop on the `audit` sidebar widget, placement section 3 row A05; the tasks.md marker tracks the block and the interim is the unfiltered tab with a working filter.

- **GIVEN** nextcloud-vue ships a preset for `actionFilter` on the `audit` sidebar widget
- **WHEN** the handler opens the History tab
- **THEN** no row with action read SHALL show until the handler widens the filter

### Requirement: The timeline can be exported (REQ-CDV-19)

You hand the history of a case to someone outside the system. The History
tab SHALL offer an Export action that downloads the rows under the current
filter as CSV with time, action, user and the changed fields. The action is
a nextcloud-vue need on `CnAuditTrailTab`; until it lands there is no
export and the tab says nothing about one.

#### Scenario: Export follows the filter
@e2e exclude `CnAuditTrailTab` has no export; placement section 3 row A05 files it against nextcloud-vue and the tasks.md marker tracks the block.

- **GIVEN** the History tab filtered to updates
- **WHEN** the handler clicks Export
- **THEN** a CSV SHALL download holding the update rows only

### Requirement: Documents, notes and mail land on the same timeline (REQ-CDV-20)

You see a document upload or a sent mail in the same list as a status
change. The History tab SHALL merge document, note and mail events for the
case with its audit rows, in one order by time. A merged feed per object
is the OpenRegister `activity` leaf, which does not exist yet:
`[blocked: openregister activity leaf]`. Interim: the audit trail over
writes only, as REQ-CDV-17 and REQ-CDV-18 describe.

#### Scenario: A document upload appears in the history
@e2e exclude The merged feed is the OpenRegister `activity` leaf (placement section 3, A05); until it ships the History tab holds audit rows only and the tasks.md marker tracks the block.

- **GIVEN** a case to which the handler uploaded bouwtekening.pdf after a status change
- **WHEN** the handler opens the History tab
- **THEN** the upload SHALL read above the status change, with the handler as actor
