# Proposal: Werkvoorraad Intelligent Queue

## Why

Intelligent work-queue management — priority-based sorting, deadline
awareness, workload balancing — is a documented competitive gap: most
zaaksystemen leave case handlers to manually triage their own workload, with
no server-computed urgency signal and no cross-handler load visibility for
coordinators. This directly hurts case-handler productivity, especially
around AWB statutory deadlines where an overlooked case becomes a legal
(dwangsom) liability.

**Relationship to the retired "werkvoorraad" board.** `openspec/specs/my-work/spec.md`
records that an earlier bespoke cases+tasks board (urgency grouping, filter
tabs, show-completed toggle, cross-app workload) was deliberately retired in
favour of a simple `CnIndexPage` list. That retirement removed a purely
client-side, ad-hoc grouping UI. This change does **not** resurrect that
board. It adds a server-authoritative, unit-tested urgency **scoring
service** (deadline math incl. termijn extensions/pauses, priority, case
age) exposed via a JSON endpoint, and surfaces its output as a lightweight
sort toggle + per-card chip on the *existing* My Work list — the simple list
stays simple; only the ordering signal gets smarter. Coordinators additionally
get a read-only cross-handler open-case count endpoint, which the old board
never had.

## What Changes

- **`WorkQueueService`** (new): computes a deterministic urgency score per
  open case/task assigned to a user. Components: nearest deadline (termijn
  instance `einddatumActueel` when an active termijn tracks the case,
  falling back to the case's own computed `deadline` field, or a task's
  `dueDate`), case/task `priority` (low/normal/high/urgent), and case age.
  Tiers: `overdue` (deadline passed) / `critical` (≤3 working days) /
  `warning` (≤7 working days) / `normal`.
- **`GET /api/work-queue`**: the authenticated user's open cases + tasks,
  each with its urgency tier, numeric score, and a score breakdown
  (deadline/priority/age components).
- **`GET /api/work-queue/workload`**: per-handler open-case counts across
  all cases, gated to the coordinator role (Nextcloud admin membership —
  the same model `SubstitutionController::isCoordinator()` already uses).
- **Frontend**: My Work (`MyWorkCards.vue`) gets a sort toggle (Urgency /
  Newest) and each card (`MyWorkCaseCard.vue`) gets an urgency chip
  (overdue → `--color-error`, critical → `--color-warning`) sourced from the
  new endpoint. A compact `WorkloadSummaryBar.vue` renders for coordinators
  when the workload endpoint succeeds.
- List ordering itself stays inside `CnIndexPage`'s self-fetch mode (so the
  existing sidebar search/facet filtering keeps working unmodified): the
  "Urgency" sort option orders by the case's own `deadline` field
  server-side (a real, always-present schema field and a reasonable proxy
  for urgency), while the chip shows the more precise
  `WorkQueueService`-computed tier (which additionally accounts for termijn
  extensions/pauses that the raw `deadline` field does not reflect).

## Impact

- **Affected specs**: new capability `werkvoorraad-intelligent-queue`;
  touches (does not replace) `my-work` (adds sort toggle + chip to the
  existing card/list requirements).
- **Affected code**:
  - `lib/Service/WorkQueueService.php` (new)
  - `lib/Controller/WorkQueueController.php` (new)
  - `appinfo/routes.php` (2 new routes)
  - `src/views/MyWorkCards.vue`, `src/views/MyWorkCaseCard.vue` (modified)
  - `src/views/WorkloadSummaryBar.vue` (new)
  - `src/utils/workQueueHelpers.js` (new, pure helpers)
  - Tests: `tests/Unit/Service/WorkQueueServiceTest.php`,
    `tests/Unit/Controller/WorkQueueControllerTest.php`,
    `tests/vitest/workQueueHelpers.spec.js`
- **No OpenRegister query/search reimplementation**: data continues to come
  from OpenRegister objects via the existing `SearchesObjects` trait; this
  change only adds a scoring/aggregation layer on top.
