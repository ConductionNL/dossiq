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


### Requirement: REQ-W2L-005 — Every dispatched notification subject is a subject the Notifier renders

`OCA\Dossiq\Notification\Notifier::prepare()` SHALL refuse a subject key that is not in
`KNOWN_SUBJECTS` by throwing `UnknownNotificationException`, and Nextcloud then drops that
notification before the recipient ever sees it. **A dispatch is therefore not a delivery.**
Every sender in the app SHALL dispatch only subject keys that are on that list, and SHALL
add a key to it, with its own wording, in the same change that starts sending it.

The list SHALL cover, at minimum, every key the app dispatches today: `note_mention`,
`case_status_changed`, `case_role_notified`, `milestone_bottleneck`, `cases_reassigned`,
`advies_aangevraagd`, `advice_requested`, `advies_ontvangen`, `advies_herinnering`,
`woo_deadline_warning`, `woo_deadline_overdue`, `dso_deadline_warning`,
`dso_deadline_critical`, `dso_deadline_overdue`, `stuf_circuit_open`, `stuf_timeout`
and `stuf_permanent_error`.

Wording SHALL be produced through `IL10N::t()` and SHALL be present in `l10n/en.json` and
`l10n/nl.json`. It SHALL NOT use `IL10N::n()`: this catalogue holds no `_singular_::_plural_`
entries and no tool writes them, so a plural lookup misses and hands every Dutch reader the
English string. A count belongs after a colon, which reads correctly at one and at twenty.

Internal identifiers SHALL stay out of the wording. A role slug, an endpoint UUID and a case
id are configuration vocabulary; they travel as subject parameters so the renderer and the
link can use them, and the recipient reads prose.

#### Scenario: A sender adds a subject key without registering it

- **GIVEN** a service dispatches a notification under a subject key absent from
  `Notifier::KNOWN_SUBJECTS`
- **WHEN** Nextcloud asks the notifier to prepare it for display
- **THEN** `prepare()` SHALL throw `UnknownNotificationException`
- **AND** the notification SHALL never reach the recipient's bell menu, with no error
  surfaced to the sender

#### Scenario: A registered subject renders as itself

- **GIVEN** a notification dispatched under `milestone_bottleneck` with a `milestone`
  parameter
- **WHEN** the notifier prepares it
- **THEN** the parsed subject SHALL name that milestone, and SHALL NOT be the wording of
  any other subject key

#### Scenario: A subject with no parameters still tells the recipient what to do

- **GIVEN** a registered subject dispatched with none of its optional parameters set
- **WHEN** the notifier prepares it
- **THEN** the parsed message SHALL be a next step the recipient can act on, never an
  empty string
