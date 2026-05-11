# Tasks: add-server-side-kpi-aggregation

## Implementation Tasks

- [ ] **T01** [V1]: Create `lib/Service/KpiAggregationService.php` with `computeKpis(string $userId): array` method — all counts via `IDBConnection` `COUNT(*)` queries, no PHP-side array iteration
- [ ] **T02** [V1]: Implement `openCount` query — cases in procest register where `JSON_EXTRACT(o.object, '$.endDate')` IS NULL or empty
- [ ] **T03** [V1]: Implement `newToday` query — cases where `startDate` matches today's ISO date prefix
- [ ] **T04** [V1]: Implement `overdueCount` query — open cases where `JSON_EXTRACT(o.object, '$.deadline')` < today's date
- [ ] **T05** [V1]: Implement `completedCount` query — cases where `endDate` starts with current month prefix (`YYYY-MM`)
- [ ] **T06** [V1]: Implement `taskCount` query — tasks where `assignee = userId` AND `status IN ('available', 'active')`
- [ ] **T07** [V1]: Implement `tasksDueToday` query — active/available tasks for user where `dueDate` matches today
- [ ] **T08** [V1]: Implement `statusBreakdown` query — `GROUP BY JSON_EXTRACT(o.object, '$.status')` on open cases
- [ ] **T08a** [V1]: Implement `avgProcessingDays` query — `AVG(DATEDIFF(JSON_EXTRACT(o.object, '$.endDate'), JSON_EXTRACT(o.object, '$.startDate')))` filtered to cases where `endDate IS NOT NULL` AND `startDate IS NOT NULL` AND `endDate` falls in the current calendar month. Return `null` (not `0`) when no completed cases match.
- [ ] **T08b** [V1]: Document the workflow invariant (`endDate IS NULL` ↔ case is not in a final status) in the `KpiAggregationService` class docblock. Reference the spec's Workflow Invariants section.
- [ ] **T09** [V1]: Create `lib/Controller/KpiController.php` with `@NoAdminRequired` `index()` method returning `JSONResponse`
- [ ] **T10** [V1]: Implement per-user cache in `KpiController` using `ICacheFactory::createLocal()` with 60s TTL and version-key pattern
- [ ] **T11** [V1]: Register route `['name' => 'kpi#index', 'url' => '/api/dashboard/kpis', 'verb' => 'GET']` in `appinfo/routes.php` before the SPA catch-all
- [ ] **T12** [V1]: Create `lib/Listener/KpiCacheInvalidationListener.php` that handles `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` — increments `"procest_kpis_{userId}_ver"` cache key, no eager recomputation
- [ ] **T13** [V1]: Register the listener in `lib/AppInfo/Application.php` for all three OpenRegister object events
- [ ] **T14** [V1]: Ensure all new classes pass `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) — fix any pre-existing issues encountered

## Test Tasks

- [ ] **T15** [V1]: Create `tests/Unit/Service/KpiAggregationServiceTest.php` — mock `IDBConnection`, assert correct query structure and return shape for all KPI fields
- [ ] **T16** [V1]: Create `tests/Unit/Controller/KpiControllerTest.php` — test cache hit/miss behaviour, assert JSON response shape, assert 401 for unauthenticated request
- [ ] **T17** [V1]: Create `tests/Unit/Listener/KpiCacheInvalidationListenerTest.php` — assert version key is incremented on each event type

## Verification Tasks

- [ ] **V01**: `GET /index.php/apps/procest/api/dashboard/kpis` returns 200 with all required fields for an authenticated user
- [ ] **V02**: All integer fields are `0` (not errors) on a fresh installation with no data
- [ ] **V03**: `cacheHit: true` is returned on second request within 60 seconds
- [ ] **V04**: `openCount` reflects actual DB count, not a capped `_limit: 1000` result
- [ ] **V05**: `taskCount` is scoped to the requesting user's assigned tasks
- [ ] **V06**: Creating a case invalidates the KPI cache (next request returns `cacheHit: false`)
- [ ] **V07**: All unit tests pass (`composer run test:unit`)
- [ ] **V08**: `composer check:strict` passes with no new violations
- [ ] **V09**: `avgProcessingDays` returns the correct SQL average when test data has 4 completed cases with durations 3/5/7/9 days (expect `6.0`); returns `null` when no completed cases this month
- [ ] **V10**: Workflow-invariant assertion — query the DB asserting that no `endDate IS NULL` case has a `currentStatus` whose `statusType.isFinal` is `true`. Fails the verification if the invariant is violated (operator must fix workflow before deploying)
