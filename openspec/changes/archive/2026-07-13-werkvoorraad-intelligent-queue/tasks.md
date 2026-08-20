# Tasks: Werkvoorraad Intelligent Queue

## Backend

- [x] T01: `WorkQueueService::scoreItem()` — pure, deterministic scoring
      function (deadline tier + business-day math, priority weight, age
      weight, no I/O) with a documented score breakdown.
- [x] T02: `WorkQueueService::computeQueue(string $userId)` — assembles the
      user's open cases (via `case_schema`, filter `assignee`) + open tasks
      (via `task_schema`, filter `assignee`), resolves each case's nearest
      active termijn deadline (falling back to the case's own `deadline`
      field) via `SearchesObjects`, scores every item, and returns them
      sorted by score descending.
- [x] T03: `WorkQueueService::computeWorkload()` — per-handler open-case
      counts across all cases (capped by a `_limit` constant, mirroring
      `SubstitutionController::SUBSTITUTION_LIMIT`).
- [x] T04: `WorkQueueController::index()` — `#[NoAdminRequired]`,
      401 when unauthenticated, otherwise `GET /api/work-queue`.
- [x] T05: `WorkQueueController::workload()` — `#[NoAdminRequired]` with an
      explicit coordinator guard (`IGroupManager::isAdmin()`, mirroring
      `SubstitutionController::requireCoordinator()`), 403 for non-admins.
- [x] T06: Register both routes in `appinfo/routes.php`.

## Frontend

- [x] T07: `src/utils/workQueueHelpers.js` — pure helpers: sort-mode →
      CnIndexPage sort-key mapping, urgency tier → chip CSS class, and a
      `{caseId: {tier, score, daysUntilDeadline}}` map builder from the
      `/api/work-queue` response.
- [x] T08: `MyWorkCards.vue` — fetch `/api/work-queue` on mount, build the
      urgency map, add an Urgency/Newest sort toggle wired to
      `sortKey`/`sortOrder`, pass the urgency map down to
      `MyWorkCaseCard.vue`.
- [x] T09: `MyWorkCaseCard.vue` — render an urgency chip (overdue =
      `--color-error`, critical = `--color-warning`, warning = a softer
      warning style, normal = no chip) using only NC CSS variables.
- [x] T10: `WorkloadSummaryBar.vue` (new) — compact per-handler open-case
      bar, rendered in `MyWorkCards.vue` only when
      `/api/work-queue/workload` succeeds (403 for non-coordinators is
      silently swallowed, no error UI).

## Tests

- [x] T11: `WorkQueueServiceTest` — scoring: no-deadline case, each tier
      boundary (overdue / critical@0,3 / warning@4,7 / normal@8), priority
      weighting, age weighting, unknown/empty priority fallback; plus
      `computeQueue`/`computeWorkload` against a fake object store.
- [x] T12: `WorkQueueControllerTest` — 401 unauthenticated on both
      endpoints, 403 non-coordinator on `workload`, 200 + correct
      user-scoping on `index`, 200 for a coordinator on `workload`.
- [x] T13: `workQueueHelpers.spec.js` — sort-mode mapping for both modes +
      unknown input, chip class for every tier + unknown/falsy, urgency-map
      builder (filters out task items, skips missing ids).

## Verification

- [x] T14: `composer test:unit` green (existing + new PHPUnit suites).
- [x] T15: `npm run test` green (existing + new vitest suites).
- [x] T16: `npm run build` succeeds.
- [x] T17: `openspec validate werkvoorraad-intelligent-queue --type change --strict`
      passes.
