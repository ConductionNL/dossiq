---
kind: code
depends_on: []
---

# Proposal: configurable-queue-urgency

Ruben's decision of 2026-10-09, reviewing the board `dossiq/DqAanMijToegewezen`:
the urgency of the "Aan mij toegewezen" list becomes configurable, and it means
what the board says it means.

## Summary

The urgency of a case in a handler's queue follows from its term, its priority
and how long it has been lying still. An administrator sets the thresholds and
weights once for the instance, a case type can override the thresholds, the
"Urgentie" sort orders by the score itself, and every case shows a pill: Te
laat, Kritiek, Bijna or Normaal.

## Why

Today every number behind the queue is a constant in
`lib/Service/WorkQueueService.php`:

- the deadline tier: below 0 working days overdue, 0 to 3 critical, 4 to 7
  warning, else normal (`tierFor`, `:569-583`);
- the score: tier base 1000/750/500/250 minus the days left (`:66-71`), plus a
  priority weight urgent 30, high 20, normal 10, low 0 (`:78-88`), plus
  `min(days since startDate, 60) * 0.5`.

Nothing of that can be set. A municipality whose Woo requests run six weeks
and whose parking objections run two cannot say that "Kritiek" means a
different thing for each. The board says the urgency follows from "de termijn,
de prioriteit en hoe lang een zaak stil ligt", and the code counts age from the
start date, which is not lying still: a case touched yesterday that started
two months ago scores as if nobody had looked at it in two months.

The Urgentie sort does not sort by urgency. It sorts by the case's `deadline`
field ascending (`src/utils/workQueueHelpers.js:35-40`), so a low-priority case
due in five days sits above an urgent one due in six, and the score the card
chip shows disagrees with the place the card has in the list.

The normal tier renders no pill (`src/views/MyWorkCaseCard.vue:196-226`); the
board draws "Normaal" on every such row. The labels read Verlopen, Kritiek and
Bijna verlopen; the board reads Te laat, Kritiek, Bijna, Normaal.

The internal word "tier" sits beside a second "urgency" in the same app: the
ITIL `case.urgency` field that `CasePriorityService` combines with impact into
priority, and `DeadlineEscalationService`'s `notificationUrgency`. Three
meanings of one word in one domain is how somebody wires the wrong one.

## What changes

1. **Admin defaults.** Four instance settings in the dossiq admin settings
   page, stored in IAppConfig: the critical threshold (default 3 working days),
   the warning threshold (default 7 working days), the priority weight and the
   idle-time weight. The defaults reproduce today's ordering of the deadline and
   priority parts exactly.
2. **Case type override.** Two optional case-type fields,
   `queueCriticalDays` and `queueWarningDays`. Empty falls back to the admin
   default. `statutoryWarningDays` and `plannedWarningDays` are NOT reused: they
   name a warning on one specific term, and the queue's deadline is the nearest
   running term of any kind (design D-2).
3. **Lying still.** The age component is replaced by the idle component: the
   days since the last activity on the case, the latest of `@self.updated` and
   the newest entry in the case journal, falling back to `startDate`.
4. **Urgentie sorts by the score.** The server ranks; the My Work list in
   Urgentie mode renders the ranked queue `GET /api/work-queue` returns, with
   search and filters applied to that ranked set (design D-4). Nieuwste keeps
   the self-fetching index.
5. **Pills.** Every case card shows its deadline tier: Te laat, Kritiek, Bijna,
   Normaal.
6. **Rename.** The internal concept becomes the deadline tier
   (`deadlineTier`, "termijnstatus" in Dutch prose) in code, wire and specs.
   The user-facing word stays "urgentie", as the board says.
7. **Board.** A section for these settings on the dossiq admin settings board
   in design-system, in its own PR.

## Out of scope

- `WorkQueueService::businessDaysBetween` and the term-instance query
  (`nearestActiveTermDeadline`). Another lane is moving both onto the
  administered working calendar. This change only calls them.
- The dashboard My Work tile (`dashboard` spec, sorted by priority then
  deadline) and the signalering widgets keep their own ordering. They can adopt
  the score in a later change.
- The ITIL `case.urgency` field and `notificationUrgency` are not touched; the
  rename removes the collision from this side.

## Affected specs

- `werkvoorraad-intelligent-queue`: scoring, endpoint, sort and pill
  requirements modified; settings, override and idle requirements added.
- `case-types`: the two override fields.
- `admin-settings`: the queue urgency section.
