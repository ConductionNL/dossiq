## MODIFIED Requirements

### Requirement: Deterministic Urgency Scoring [MVP]

The system MUST provide a pure, deterministic scoring function that maps
(deadline, priority, last-activity date, thresholds, weights) to a deadline
tier, a numeric score and a score breakdown. The deadline tier (termijnstatus)
is decided by working days until the resolved deadline against the effective
thresholds: `overdue` below 0, `critical` from 0 up to and including the
critical threshold, `warning` above that up to and including the warning
threshold, `normal` above that or when there is no deadline. The score is
`tierBase - workingDaysLeft + priorityWeight * priorityPoints + idleWeight *
min(idleDays, 60)`, with tier bases 1000, 750, 500 and 250 and priority points
urgent 3, high 2, normal 1, low 0 (anything else 1). The weights are bounded so
the priority and idle parts never lift an item above an item one tier up. The
function MUST NOT perform I/O. The internal concept is named the deadline tier
(`deadlineTier`) in code and on the wire, so it cannot be confused with the
ITIL `urgency` field of a case.

@e2e exclude A pure function with no browser surface; asserted by tests/Unit/Service/WorkQueueServiceTest.php.

#### Scenario: No deadline
- GIVEN an item with no resolvable deadline
- WHEN it is scored
- THEN its deadline tier MUST be `normal` and `daysUntilDeadline` MUST be `null`

#### Scenario: Overdue tier
- GIVEN an item whose deadline is 2 working days in the past
- WHEN it is scored
- THEN its deadline tier MUST be `overdue`

#### Scenario: Critical tier boundary
- GIVEN no threshold is configured anywhere
- AND an item whose deadline is exactly 3 working days away
- WHEN it is scored
- THEN its deadline tier MUST be `critical`
- AND an item whose deadline is exactly 4 working days away MUST be `warning`

#### Scenario: Warning tier boundary
- GIVEN no threshold is configured anywhere
- AND an item whose deadline is exactly 7 working days away
- WHEN it is scored
- THEN its deadline tier MUST be `warning`
- AND an item whose deadline is exactly 8 working days away MUST be `normal`

#### Scenario: Configured thresholds move the boundaries
- GIVEN the critical threshold is 5 and the warning threshold is 10
- WHEN items 5, 6, 10 and 11 working days from their deadline are scored
- THEN their deadline tiers MUST be `critical`, `warning`, `warning` and `normal`

#### Scenario: Priority increases score within a tier
- GIVEN two items in the same deadline tier, one `priority: urgent` and one
  `priority: low`, and a priority weight above 0
- WHEN both are scored
- THEN the `urgent` item's score MUST be strictly higher

#### Scenario: A weight of zero switches its part off
- GIVEN the priority weight is 0 and the idle weight is 0
- WHEN two items with the same deadline but different priority and idle days are scored
- THEN their scores MUST be equal

#### Scenario: The tier always outranks the weights
- GIVEN both weights at their maximum
- AND an urgent item in the `warning` tier that has been lying still for 90 days
- AND a low-priority item in the `critical` tier touched today
- WHEN both are scored
- THEN the `critical` item's score MUST be higher

#### Scenario: Default weights keep today's priority points
- GIVEN no weight is configured
- WHEN items with priority urgent, high, normal and low are scored
- THEN their priority parts MUST be 30, 20, 10 and 0

### Requirement: Personal Work Queue Endpoint [MVP]

The system MUST expose `GET /api/work-queue`, returning the authenticated
user's open cases and open tasks, each annotated with its deadline tier
(`deadlineTier`), days until the deadline, idle days (`idleDays`), score and
score breakdown (`deadline`, `priority`, `idle`), sorted by score descending.
The cases MUST be read with the filters the My Work list uses (`assignee`
the caller, `statusHiddenInLists: false`, `isDraft: false`, `endDate` empty)
and an explicit limit of 1000, so no open case is left out of the ranking by
a default page size. Each case item MUST carry the case row (`case`) so a list
can render it from the ranked answer. Unauthenticated callers MUST receive 401.

@e2e exclude The ranking is data-dependent and needs per-user seeded cases with distinct deadlines; asserted by tests/Unit/Service/WorkQueueServiceTest.php and tests/Unit/Controller/WorkQueueControllerTest.php.

#### Scenario: Only the caller's open items are returned
- GIVEN user "Jan" is assignee on 2 open cases and 1 closed case, and user
  "Marie" is assignee on 1 open case
- WHEN Jan calls `GET /api/work-queue`
- THEN the response MUST contain exactly Jan's 2 open cases
- AND MUST NOT contain Jan's closed case or Marie's case

#### Scenario: The case search names its limit and the list's filters
- WHEN the queue reads the caller's cases
- THEN the search MUST pass `assignee`, `statusHiddenInLists: false`, `isDraft: false` and `_limit: 1000`

#### Scenario: A case item carries its row
- GIVEN Jan has one open case
- WHEN Jan calls `GET /api/work-queue`
- THEN that case's item MUST carry the case row under `case`
- AND MUST carry `deadlineTier` and `idleDays`, and MUST NOT carry `tier`

#### Scenario: Unauthenticated call is rejected
- GIVEN no authenticated session
- WHEN `GET /api/work-queue` is called
- THEN the system MUST respond 401

### Requirement: Urgency-Aware My Work Sorting [MVP]

The My Work card list MUST offer a sort toggle between "Urgency" (default)
and "Newest". "Urgency" MUST order the reader's cases by the score
`GET /api/work-queue` computes, highest first, by rendering the ranked case
rows that endpoint returns; the search box and the sidebar filters MUST narrow
that ranked set without changing its order. "Newest" MUST keep the
self-fetching `CnIndexPage` ordered by `startDate` descending. When the work
queue cannot be computed, "Urgency" MUST fall back to the self-fetching list
ordered by `deadline` ascending and MUST say in one line that the order is by
deadline.

#### Scenario: Urgency is the default sort
- GIVEN Jan opens My Work for the first time
- THEN the list MUST be in the order of the work-queue score, highest first

#### Scenario: Urgency does not follow the deadline alone
@e2e exclude The order depends on two seeded cases with chosen deadlines and priorities; asserted by tests/vitest/workQueueHelpers.spec.js over the ranked list builder.

- GIVEN two of Jan's cases in the `warning` tier, an urgent one due in 6
  working days and a low one due in 5
- WHEN Jan views My Work sorted by Urgency
- THEN the urgent case MUST be listed first

#### Scenario: Search narrows the ranked list
@e2e exclude Asserted by tests/vitest/workQueueHelpers.spec.js; the browser path is the same CnIndexPage search event the Newest mode already exercises.

- GIVEN Jan views My Work sorted by Urgency
- WHEN Jan searches for a case's title
- THEN only matching cases MUST remain, in their ranked order

#### Scenario: Toggling to Newest re-sorts
- GIVEN Jan is viewing My Work sorted by Urgency
- WHEN Jan selects the "Newest" sort option
- THEN the list MUST re-sort by case start date, most recent first

#### Scenario: The queue fails and the list says it orders by deadline
@e2e exclude Forcing the work-queue endpoint to fail needs a broken backend; asserted by tests/vitest/workQueueHelpers.spec.js over the mode resolver.

- GIVEN `GET /api/work-queue` fails
- WHEN Jan views My Work sorted by Urgency
- THEN the list MUST be the self-fetching list ordered by deadline
- AND one line MUST say the order is by deadline

### Requirement: Urgency Chip on Cards [MVP]

Each My Work card MUST show a pill for its deadline tier, sourced from
`GET /api/work-queue`, labelled as on the board `dossiq/DqAanMijToegewezen`:
`overdue` reads "Te laat" (English source "Late") and `critical` reads
"Kritiek" ("Critical"), both in the error tint; `warning` reads "Bijna"
("Soon") in the warning tint; and `normal` reads "Normaal" ("Normal") in a
neutral style. The two error-tinted pills differ by their label, as the board
draws them. Colours MUST come from Nextcloud CSS variables only.

#### Scenario: Overdue chip
@e2e exclude Needs a seeded overdue case; the label and class mapping is asserted by tests/vitest/workQueueHelpers.spec.js.

- GIVEN a case whose deadline tier is `overdue`
- THEN its card MUST show the pill "Te laat" in the error tint

#### Scenario: Critical chip
@e2e exclude Needs a seeded case two working days from its deadline; asserted by tests/vitest/workQueueHelpers.spec.js.

- GIVEN a case whose deadline tier is `critical`
- THEN its card MUST show the pill "Kritiek" in the error tint, as the board draws it

#### Scenario: Normal tier shows no chip
- GIVEN a case with no deadline
- THEN its card MUST NOT show a coloured chip
- AND its card MUST show the neutral pill "Normaal"

## ADDED Requirements

### Requirement: An administrator sets the queue thresholds and weights

The critical threshold, the warning threshold, the priority weight and the
idle-time weight MUST be read from the app config keys `queue_critical_days`
(default 3), `queue_warning_days` (default 7), `queue_priority_weight`
(default 10) and `queue_idle_weight` (default 0.5). A value that does not parse
MUST read as its default and a value out of bounds MUST be clamped (critical 0
to 60, warning 0 to 120, priority weight 0 to 50, idle weight 0 to 1.5). A
warning threshold below the critical threshold MUST read as equal to it. The
queue MUST NOT fail on a stored setting.

@e2e exclude Read-time normalisation has no browser surface; asserted by tests/Unit/Service/Queue/QueueUrgencySettingsTest.php.

#### Scenario: Nothing stored reads as the defaults
- GIVEN none of the four keys is set
- THEN the settings MUST read 3, 7, 10 and 0.5

#### Scenario: A broken value reads as the default
- GIVEN `queue_critical_days` holds `abc`
- THEN the critical threshold MUST read 3

#### Scenario: Warning below critical closes the Bijna band
- GIVEN the critical threshold is 5 and the warning threshold is 2
- WHEN an item 5 working days from its deadline and one 6 days from it are scored
- THEN they MUST be `critical` and `normal`

### Requirement: A case type overrides the thresholds

A case type MAY set `queueCriticalDays` and `queueWarningDays`. Each one that
is set MUST replace the admin default for the cases of that type; each one
that is empty MUST fall back to the admin default on its own. The fields
`statutoryWarningDays` and `plannedWarningDays` MUST NOT steer the deadline
tier.

@e2e exclude The effect shows only on seeded cases of a configured type; asserted by tests/Unit/Service/WorkQueueServiceTest.php.

#### Scenario: The case type's own threshold wins
- GIVEN the admin critical threshold is 3 and case type "Woo-verzoek" sets `queueCriticalDays` 10
- WHEN a Woo-verzoek case 8 working days from its deadline is scored
- THEN its deadline tier MUST be `critical`

#### Scenario: An empty override falls back
- GIVEN case type "Melding" sets `queueWarningDays` 12 and no `queueCriticalDays`
- WHEN a Melding case 3 working days from its deadline is scored
- THEN its deadline tier MUST be `critical`, by the admin default of 3

### Requirement: The idle part counts the days a case lies still

The idle part of a case's score MUST count the calendar days since the last
activity on the case, which is the latest of the case's `@self.updated` and
the newest entry in its journal (`case.activity`). With neither, it MUST count
from `startDate`. A moment in the future MUST count as today. The days counted
MUST be capped at 60. Tasks MUST have no idle part.

@e2e exclude A date computed on the server from stored timestamps; asserted by tests/Unit/Service/WorkQueueServiceTest.php.

#### Scenario: A recent edit resets the idle days
- GIVEN a case started 40 days ago whose `@self.updated` is 2 days ago
- WHEN it is scored
- THEN its idle days MUST be 2

#### Scenario: A journal entry newer than the save counts
- GIVEN a case whose `@self.updated` is 9 days ago and whose newest journal entry is 4 days ago
- WHEN it is scored
- THEN its idle days MUST be 4

#### Scenario: No activity counts from the start
- GIVEN a case with no `@self.updated`, no journal and a `startDate` 12 days ago
- WHEN it is scored
- THEN its idle days MUST be 12
