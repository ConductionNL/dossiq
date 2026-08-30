# ncvue-w2-leaves-adoption Specification

## Purpose
TBD - created by archiving change ncvue-w2-leaves-adoption. Update Purpose after archive.
## Requirements
### Requirement: REQ-W2L-001 — Saved Views On The Main List Pages

The Cases, Bezwaren, Tasks, Voorstellen, Advice, and Beroepen index pages SHALL declare
`allowSavedViews: true` in their manifest `config`, so `CnIndexPage` renders the saved-views
control (list/apply/save) for those pages.

#### Scenario: A user saves the current filter/sort as a named view on the Cases page

- **GIVEN** a user is on the Cases index page with a non-default filter and sort applied
- **WHEN** they open the saved-views control and save the current state as a named view
- **THEN** `CnIndexPage` (with `allowSavedViews: true`) SHALL persist the view via
  OpenRegister's `/api/views` endpoint and the view SHALL appear in the saved-views list on
  next visit

#### Scenario: Saved views are available on every main list page, not just Cases

- **GIVEN** the manifest declares `allowSavedViews: true` on Cases, Bezwaren, Tasks,
  Voorstellen, Advice, and Beroepen
- **WHEN** any of those pages is rendered
- **THEN** the saved-views control SHALL be present, scoped to that page's own
  register/schema

### Requirement: REQ-W2L-002 — Multi-Column Sort Rides The Library Unmodified

Dossiq SHALL NOT implement or shadow any sort-handling logic of its own; multi-column
sort (shift+click on a column header, persisted `_order`) SHALL work on every dossiq
index page purely because `CnIndexPage`/`CnDataTable` implement it in nc-vue.

#### Scenario: Shift+click adds a secondary sort key on the Cases table

- **GIVEN** a user is viewing the Cases table view, already sorted by one column
- **WHEN** they shift+click a second column header
- **THEN** `CnDataTable` SHALL append that column as a secondary sort key and emit a
  `sort` event that `CnIndexPage` persists as `_order`, with no dossiq-side code
  involved in the sort computation

### Requirement: REQ-W2L-003 — Note `@mention` Triggers A Real Nextcloud Notification

The mentioned user(s) SHALL receive a real Nextcloud bell-menu notification when a user
saves a note containing an `@mention` on a case's detail page, dispatched via dossiq's
own `POST /api/notes/mention` endpoint and rendered by a registered `INotifier`.

#### Scenario: Mentioning a colleague in a case note notifies them

- **GIVEN** a user viewing a case's detail page opens the "Notes" sidebar tab
  (`CaseNotesTab`, wrapping the library's `CnNotesTab`) and saves a note containing
  `@bob`
- **WHEN** `CnNotesTab` emits `mention` with
  `{ objectId, register, schema, noteId, mentionedUserIds: ['bob'] }`
- **THEN** `CaseNotesTab` SHALL POST that payload to `/api/notes/mention`
- **AND** `NotesController::mention()` SHALL delegate to
  `MentionNotificationService::notifyMention()`, which SHALL create and dispatch one
  `IManager` notification addressed to `bob`
- **AND** the registered `Notifier` SHALL render it in bob's bell menu with a subject
  naming the mentioning user, an absolute-URL icon, and a message

#### Scenario: Self-mentions and duplicate mentions are not double-notified

- **GIVEN** a note's `mentionedUserIds` contains the note author's own uid, or the same
  mentioned uid twice
- **WHEN** `MentionNotificationService::notifyMention()` runs
- **THEN** the author SHALL NOT receive a notification for their own mention
- **AND** a uid mentioned twice SHALL receive exactly one notification

#### Scenario: A notification failure for one recipient does not block the others

- **GIVEN** two mentioned users, where dispatching the first notification throws
- **WHEN** `notifyMention()` processes the recipient list
- **THEN** the failure SHALL be caught and logged as a warning
- **AND** the remaining recipient(s) SHALL still be notified
- **AND** the endpoint SHALL still return `200` with the actual notified count (the note
  itself is already saved by the time this endpoint runs; a notification failure must
  never surface as an error to the note author)

### Requirement: REQ-W2L-004 — Version History Sidebar Tab On Every Detail Page

Every dossiq detail page SHALL surface a "Version history" sidebar tab, beside the
existing "History" (audit-trail) tab, rendering the library's `CnVersionHistory`
field-by-field diff viewer.

#### Scenario: Version history is available beside audit trail on the case detail

- **GIVEN** a user opens a case detail page's sidebar
- **WHEN** they view the tab strip
- **THEN** both a "History" tab (existing `audit` widgets-tab) and a "Version history"
  tab (new `component:` tab resolving `VersionHistoryLeafTab` /
  `leafTab('version-history')`) SHALL be present
- **AND** the "Version history" tab SHALL render `CnVersionHistory`, receiving the same
  `objectId`/`register`/`schema`/`apiBase` context as every other sidebar tab

#### Scenario: Version history is present on all 21 detail pages, not just CaseDetail

- **GIVEN** the manifest declares a `version-history` `component:` tab on every detail
  page's `sidebar.tabs[]` (CaseDetail, BezwaarDetail, TaskDetail, VoorstelDetail, …)
- **WHEN** any of those detail pages is rendered
- **THEN** the "Version history" tab SHALL be present and functional on that page too

