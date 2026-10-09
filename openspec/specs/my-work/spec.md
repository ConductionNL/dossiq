---
status: done
---

# My Work Specification

**Name collision, read before editing either file.** This spec is about the
`/my-work` case index — labelled "Assigned to me" in the navigation since
`add-work-queue`, and still called "My Work" here because that was its name
when this spec was written. The app's *landing page* (route `/`, nav label
"My work") is a different surface, specified in
`openspec/specs/my-work-landing/spec.md`. If you are looking for the widgets a
handler sees on opening the app, that is the other file.

## Purpose

My Work is the personal starting point for a case handler: the list of cases
assigned to the signed-in user. It answers the daily question "what is on my
plate?" by scoping the standard case index to `assignee == currentUser` and
rendering it as a card list (with a table toggle).

It is one of three surfaces in the My work group, and each answers a different
question. **Queue** (`/queue`) holds what nobody has picked up: no `assignee` and
`isFinalStatus` false. **Assigned to me** (this page) holds what is the signed-in
user's. **All cases** holds everything. Assigning a case moves it from the Queue to
this page; All cases shows it either way. See `openspec/changes/add-work-queue`. It deliberately reuses the
same index engine as the "All cases" view rather than a bespoke board, so
filtering, sorting, the sidebar and navigation behave identically.

**Scope note (2026-07):** My Work was simplified from a bespoke cases+tasks
"werkvoorraad" board (urgency grouping, filter tabs, show-completed) to a
standard `CnIndexPage` card list of assigned cases. Task aggregation, urgency
grouping and cross-app (Pipelinq) workload were dropped from this view.

**Update 2026-09-13 (dashboard-my-work-split):** the personal-workload
dashboard widgets this note used to point at have moved off the Dashboard
onto the new My Work landing page (`openspec/specs/my-work-landing/spec.md`,
route `/`) — they are no longer on `/dashboard`.

**Competitive context**: Dimpact ZAC provides a configurable worklist with
signaling cards and real-time updates; xxllnc Zaken uses phase-bound task
lists; Flowable offers a unified task inbox with claiming and delegation.
Dossiq takes a deliberately simple approach: the current user's cases in the
standard index, plus dashboard widgets for tasks/overdue at-a-glance.

## Data Sources

My Work queries one OpenRegister schema in the `dossiq` register:
- **Cases**: schema `case`, base filter `assignee == currentUser` (the signed-in
  user's uid, resolved client-side from `@nextcloud/auth`). No status filter is
  applied — every case assigned to the user is listed regardless of lifecycle
  state, so a handler sees their full assigned load.

## Requirements

### Requirement: Personal Case Index [MVP]

The system MUST provide a "My Work" navigation entry that opens a case index
scoped to the current user's assignments, implemented as a thin `CnIndexPage`
wrapper in `src/views/MyWorkCards.vue` (register `dossiq`, schema `case`,
base filter `{ assignee: <current uid> }`). It is a `type: "custom"` manifest
page because the stock index base-filter resolves only `@route.*` tokens, not
the `@me` current-user token; the wrapper injects the resolved uid.

#### Scenario: View assigned cases
@e2e exclude Requires cases pre-assigned to the current user; the data-dependent
list contents are not assertable without pre-seeded per-user data.

- GIVEN user "Jan" is `assignee` on 3 cases and on 0 other cases
- WHEN Jan navigates to "My Work"
- THEN the system MUST display exactly those 3 cases
- AND a case where Jan is NOT the assignee MUST NOT appear

#### Scenario: Card and table view
@e2e tests/e2e/spec-coverage/my-work.spec.ts

- GIVEN Jan is viewing My Work
- THEN the list MUST default to card view and offer a card/table toggle
- AND the table view MUST show the columns: identifier, title, case type,
  status, deadline

### Requirement: Card Display [MVP]

Each case card MUST present the case in human-readable form, implemented in
`src/views/MyWorkCaseCard.vue`.

@e2e exclude Requires an assigned case with a case type + status; card field
rendering is data-dependent.

#### Scenario: Card fields
- GIVEN an assigned case with a caseType and a status
- THEN the card MUST display:
  - The case title
  - A truncated description (when present)
  - The identifier (e.g. "2026-0118")
  - The **case-type name** (not its raw UUID) resolved from the caseType map
  - The **status name** (not its raw UUID) resolved from the statusType map
  - The deadline date when set
- AND a case whose deadline is in the past MUST show the deadline in an error
  colour (overdue), not relying on colour alone (the "Deadline:" label remains)

#### Scenario: Case-type / status name resolution
- GIVEN card view does not apply column formatters
- WHEN My Work renders its cards
- THEN the parent index MUST load the `caseType` and `statusType` collections
  once and pass UUID→name maps to each card so names render, never raw UUIDs

### Requirement: Item Navigation [MVP]

Opening a case from My Work MUST navigate to that case's detail view.

@e2e exclude Requires an assigned case to click; data-dependent navigation.

#### Scenario: Open a case
- GIVEN case 2026-0118 appears in My Work
- WHEN the user clicks the card (or the table row)
- THEN the system MUST navigate to the `CaseDetail` route for that case id

### Requirement: Empty State [MVP]

When the current user has no assigned cases, My Work MUST show the standard
index empty state (provided by `CnIndexPage`) rather than an error or a blank
page.

#### Scenario: No assigned cases
- GIVEN the current user is the assignee on no cases
- WHEN they navigate to "My Work"
- THEN the system MUST display the index empty state and MUST NOT error

### Requirement: Personal-Workload Dashboard Widgets [MVP]

Independently of the My Work index, the system MUST provide Nextcloud dashboard
widgets that summarise the user's workload at a glance.

@e2e exclude NC dashboard widget IWidget PHP classes + Vue bundle loading;
covered by PHPUnit + smoke tests, not Playwright browser assertions.

#### Scenario: My Tasks / Overdue widgets
- GIVEN the Nextcloud dashboard is displayed
- THEN the Dossiq "My Tasks" widget (`lib/Dashboard/MyTasksWidget.php`) MUST
  summarise the user's assigned tasks
- AND the "Overdue Cases" widget (`lib/Dashboard/OverdueCasesWidget.php`) MUST
  summarise overdue cases with a red indicator
- AND clicking a widget MUST navigate into the app

#### Scenario: Dashboard preview panel
- GIVEN the user opens the Dossiq app dashboard (home view)
- THEN `src/views/dashboard/MyWorkPreview.vue` MUST show a summary of the
  user's assigned work

### Requirement: Lenses on the Cases index [V1]

You switch between your cases, unclaimed cases and all cases on one list.
The `Cases` page (`src/manifest.json`, type `index` over `case`) SHALL
carry `quickFilters` chips in this order: All (no filter), Mine
(`assignee = @me`, `isFinalStatus = false`), Unclaimed
(`assignee = "IS NULL"`, `isFinalStatus = false`), Closed
(`isFinalStatus = true`) and Overdue (`deadline lt @today`,
`isFinalStatus = false`). All SHALL be the default chip. Exactly one chip
is active at a time and choosing a chip SHALL replace the previous chip's
filter, not stack on it. The Unclaimed chip SHALL use the same filter as
the Queue page's base filter, so the two lists agree. The Queue page and
the My Work page SHALL stay as they are: no page is folded, retired or
moved by this requirement.

**Decision D-default, revised by Ruben.** This requirement asked for Mine
as the default chip and it now asks for All. A `quickFilters` list
activates a chip on mount: the one marked `default`, and the first one
when none is marked. A Mine default therefore narrows every reader's first
paint to their own rows before they have chosen anything, and a person
with no cases lands on an empty list that reads as an empty register
rather than as a filter. All as the default keeps the landing view the one
the page has always shown; Mine is one click away and stays visibly
active once chosen. This is the pattern `parties-on-the-case` established
on both indexes.

#### Scenario: All is the lens you land on, Mine is one click away
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open case assigned to the signed-in user and an open case assigned to another user
- **WHEN** you open the Cases page
- **THEN** the chip All SHALL be active and the list SHALL show both cases
- **AND** choosing the chip Mine SHALL show the case assigned to you and SHALL NOT show the other user's case

#### Scenario: Unclaimed shows what nobody has picked up
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open case with no assignee and an open case assigned to the signed-in user
- **WHEN** you choose the chip Unclaimed
- **THEN** the list SHALL show the unassigned case and SHALL NOT show the assigned one
- **AND** the Queue page SHALL show the same unassigned case

#### Scenario: All shows every open and closed case
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** an open case assigned to another user and a closed case
- **WHEN** you choose the chip All
- **THEN** the list SHALL show both cases

#### Scenario: Chips replace each other
@e2e tests/e2e/case-list-lenses.spec.ts

- **GIVEN** the Cases page with the chip Unclaimed active
- **WHEN** you choose the chip Mine
- **THEN** only Mine SHALL be active
- **AND** the list SHALL show no unassigned case

### Requirement: One queue holds everything waiting on a person (REQ-QUEUE-02)

A person's queue SHALL hold, from the declared sources, the cases assigned
to them, the cases where they hold the coordinator seat, their open tasks,
consultations asked of them, approvals awaiting their signature, mentions
of them, and work they cover for an absent colleague. An item SHALL leave
the queue when the thing it points at is done, taken over or withdrawn. A
person SHALL NOT be able to dismiss an item whose work still stands.

#### Scenario: a caseworker opens one page, not six
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a handler with assigned cases, a coordinator seat, two open tasks and one consultation
- **WHEN** they open their queue
- **THEN** all of them SHALL be listed

#### Scenario: an item closes with its work
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a queue item pointing at an open task
- **WHEN** the task is completed
- **THEN** the item SHALL leave the queue

#### Scenario: a person cannot dismiss live work
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a queue item for a case still assigned to the person
- **WHEN** they try to remove it
- **THEN** it SHALL stay
- **AND** they SHALL be offered to hide its group for today instead

#### Scenario: covering for an absent colleague reaches the queue
@e2e exclude Needs a second account and an active substitution window. The shared e2e instance signs in as one user, and seeding an absence there routes a real colleague's real work to the test account; the routing itself is covered by `SubstitutionServiceTest` and the queue side by `QueueSourceContractTest::testCoveredWorkIsMarked`.

- **GIVEN** a handler covering for an absent colleague
- **WHEN** they open their queue
- **THEN** the colleague's waiting work SHALL be listed and marked as covered

### Requirement: A daily digest arrives only when there is something to say (REQ-QUEUE-03)

dossiq SHALL send a person a daily digest of their open work, at a time
they choose, over the platform's notification dialect. A person with an
empty queue SHALL receive no digest. The digest SHALL name what is waiting
and what is overdue and SHALL link into the queue. It SHALL NOT repeat the
assignment notice. It SHALL be switchable off through the platform's
notification preferences.

#### Scenario: the digest arrives at the chosen time
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a handler with four waiting items and a chosen time of 08:00
- **WHEN** the digest job runs
- **THEN** they SHALL receive one message naming those four

#### Scenario: an empty queue sends nothing
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a handler with an empty queue
- **WHEN** the digest job runs
- **THEN** they SHALL receive no message

#### Scenario: the digest is not the assignment notice
@e2e exclude The two messages are different code paths with no shared browser surface: the notice is the register's `caseAssigned` notification and the digest is the `workDigest` record. Covered by `DailyDigestJobTest::testAPersonWithWaitingWorkGetsOneDigest`, which asserts the digest names the waiting count rather than one case.

- **GIVEN** a case assigned to a handler this morning
- **WHEN** the digest runs that evening
- **THEN** the digest SHALL list the case
- **AND** it SHALL NOT be the assignment notice message

#### Scenario: a person switches it off where they switch off everything else
@e2e exclude Asserting that NO message was sent needs the job to run in the browser's own hour, which a Playwright run cannot arrange. Covered by `DailyDigestJobTest::testAPersonWhoSwitchedItOffGetsNothing`.

- **GIVEN** a handler who disabled the digest in the notification preferences
- **WHEN** the digest job runs
- **THEN** they SHALL receive no message

### Requirement: One screen closes out the day (REQ-QUEUE-04)

dossiq SHALL offer a screen listing everything a person touched today,
with a place to record an update per item. Where humaniq is present, the
screen SHALL place humaniq's hours leaf per item so time is recorded
there. dossiq SHALL NOT store hours. Where humaniq is absent, the screen
SHALL show no time field.

#### Scenario: everything touched today, in one place
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a handler who touched five cases and two tasks today
- **WHEN** they open the end-of-day screen
- **THEN** all seven SHALL be listed

#### Scenario: an update is recorded per item
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** the end-of-day screen
- **WHEN** a handler writes an update against one case
- **THEN** it SHALL be recorded on that case

#### Scenario: time goes to humaniq, or nowhere
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** an instance with humaniq present
- **WHEN** a handler records time on an item
- **THEN** it SHALL be written through humaniq's hours leaf

#### Scenario: no humaniq, no time field
@e2e exclude Requires an instance WITHOUT humaniq, and the e2e instance is shared, so uninstalling an app for one spec breaks every other suite on it. Covered by `tests/vitest/endOfDayScreen.spec.js`, "shows the time box only when the leaf is really there".

- **GIVEN** an instance without humaniq
- **WHEN** the end-of-day screen is opened
- **THEN** no time field SHALL be offered

### Requirement: A person plans an item with no case (REQ-QUEUE-05)

A person SHALL be able to plan an item on their own agenda without
attaching it to a case, optionally from a template. It SHALL be a calendar
event on that person's calendar, SHALL reach their queue as a declared
source, and SHALL NOT be a case, SHALL NOT enter any case count, and SHALL
NOT appear in any case report.

#### Scenario: a planned item with no case
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a handler
- **WHEN** they plan an item from a template with no case
- **THEN** it SHALL appear on their calendar and in their queue

#### Scenario: it is not a case
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a planned item with no case
- **WHEN** the open case count and the case list are read
- **THEN** it SHALL be in neither

### Requirement: A person keeps a private stage on a shared case (REQ-QUEUE-06)

A person SHALL be able to set their own stage on a case, visible only to
them. It SHALL NOT change the case's status, SHALL NOT be visible to any
other person, and SHALL NOT enter any report. The case page SHALL show the
case's own status prominently and the personal stage as private to the
reader.

#### Scenario: a personal triage lane on a shared case
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a case shared by two handlers
- **WHEN** the first sets their personal stage to Wachten op advies
- **THEN** the second SHALL NOT see it

#### Scenario: the case's own status is unchanged
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a case in status In behandeling
- **WHEN** a handler sets a personal stage
- **THEN** the case status SHALL still read In behandeling

#### Scenario: a personal stage is not a report dimension
@e2e exclude A report cannot group by a field that does not exist on the object, and the stage is stored in the reader's own preferences. Covered structurally by `PersonalStageTest::testTheServiceCannotReachTheObjectStore`, which asserts the service has no register dependency at all.

- **GIVEN** cases carrying personal stages
- **WHEN** a status report is run
- **THEN** it SHALL group by the case status only

### Requirement: A digest names only what its recipient may read (REQ-QUEUE-03a)

The daily digest job SHALL compose each person's digest with that person as the acting user, so every read is answered the way it is answered for them. The previous acting user SHALL be restored even when composing fails. Only the digest record SHALL be written as the background service account. Without a usable account nothing SHALL be composed or sent.

#### Scenario: two recipients, two digests, no leak
@e2e exclude a cron job with no browser gesture; covered by DailyDigestJobServiceAccountTest::testEachDigestListsOnlyWhatItsRecipientMayRead and the live check in the PR

- **GIVEN** a case assigned to alice that only bob may read
- **WHEN** the digest job runs for alice and bob
- **THEN** alice's digest SHALL NOT name that case
- **AND** each digest SHALL name only cases its recipient may read

#### Scenario: the digest record is written as the account
@e2e exclude a cron job with no browser gesture; covered by DailyDigestJobServiceAccountTest::testTheDigestIsSentAsTheAccount

- **WHEN** a digest is sent
- **THEN** the `workDigest` record SHALL be written by the background service account
- **AND** no user SHALL remain signed in after the run

## Non-Functional Requirements

- **Performance**: My Work reuses the index self-fetch; it MUST page/limit like
  the standard case index rather than loading unbounded results.
- **Accessibility**: Cards MUST be keyboard-operable (focusable, Enter/Space to
  open) and overdue state MUST NOT rely on colour alone (the "Deadline:" text
  label is always present). Content MUST meet WCAG AA.
- **Localization**: All labels MUST support English + Dutch via `t()`.
- **Responsiveness**: The card grid MUST adapt to narrow viewports.

---

### Current Implementation Status

**Implemented (MVP).**

- **My Work index**: `src/views/MyWorkCards.vue` — a `CnIndexPage` card list
  (card default + table toggle) over `dossiq`/`case`, base filter
  `{ assignee: <uid from @nextcloud/auth> }`, wired as the `MyWork`
  `type: "custom"` manifest page (`component: MyWorkView`).
- **Card**: `src/views/MyWorkCaseCard.vue` — title, description, identifier,
  case-type + status names (resolved via parent-supplied UUID→name maps because
  card view does not apply column formatters), deadline with overdue
  highlighting; an urgency chip (see below); click emits `open` → `CaseDetail`.
- **Sort toggle + urgency chip** (see capability `werkvoorraad-intelligent-queue`):
  a server-computed urgency score (deadline incl. termijn extensions/pauses,
  priority, case age) drives an Urgency/Newest sort toggle and a per-card
  urgency chip, sourced from `GET /api/work-queue`. This is deliberately
  narrower than the retired board below — the list itself stays a plain
  `CnIndexPage`; only the ordering signal and the chip are new.
- **Dashboard widgets** (unchanged, still present): `lib/Dashboard/MyTasksWidget.php`,
  `lib/Dashboard/OverdueCasesWidget.php`, `lib/Dashboard/CasesOverviewWidget.php`
  + `src/views/dashboard/MyWorkPreview.vue`.

**Deliberately dropped (was the old werkvoorraad board):**
- Task aggregation, All/Cases/Tasks filter tabs, client-side urgency grouping
  (Overdue/Due-this-week/Upcoming/No-deadline), the show-completed toggle, and
  cross-app (Pipelinq) workload. The `Werkvoorraad` work-queue page was also
  retired (the Workflow Board covers the in-progress view). Sorting-by-priority
  was later reintroduced in server-computed form (see above) — the ad-hoc
  client-side board it originally shipped in was not.

**Not implemented:**
- `@me` support in the nc-vue index base filter (would let My Work be a pure
  manifest `type: "index"` page instead of a wrapper).

### Standards & References

- **ZGW APIs (VNG Realisatie)**: Cases correspond to `Zaak`; `assignee` is the
  handler (behandelaar).
- **WCAG 2.1 AA**: Overdue indicators use text + colour, not colour alone.
- **NL Design System**: CSS variables for colours/spacing supporting theming.
