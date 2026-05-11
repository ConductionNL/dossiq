# Delta: add-server-side-kpi-aggregation

Capability: `kpi-aggregation` (new)
Affects canonical spec: `openspec/specs/dashboard/spec.md`

## ADDED Requirements

### Requirement: REQ-KPI-001 — KPI Aggregation Endpoint [V1]

The system MUST expose a `GET /index.php/apps/procest/api/dashboard/kpis` endpoint that returns pre-computed dashboard KPI values as a JSON object.

**Feature tier**: V1

#### Scenario: Endpoint returns stable JSON shape

- **GIVEN** an authenticated user sends `GET /index.php/apps/procest/api/dashboard/kpis`
- **THEN** the response MUST have status code 200
- **AND** the response MUST have `Content-Type: application/json`
- **AND** the body MUST contain all of the following fields with their types:
  - `openCount` (integer ≥ 0) — count of cases with no `endDate` (not yet closed)
  - `newToday` (integer ≥ 0) — count of cases whose `startDate` matches today's date
  - `overdueCount` (integer ≥ 0) — count of open cases where `deadline` < today
  - `completedCount` (integer ≥ 0) — count of cases whose `endDate` falls in the current calendar month
  - `taskCount` (integer ≥ 0) — count of tasks assigned to the current user with status `available` or `active`
  - `tasksDueToday` (integer ≥ 0) — count of those tasks whose `dueDate` matches today
  - `statusBreakdown` (array of `{status: string, count: integer}`) — open case counts grouped by status value
  - `avgProcessingDays` (number ≥ 0, or `null` if no completed cases this month) — average days between `startDate` and `endDate` for cases completed in the current calendar month, computed via `AVG(DATEDIFF(endDate, startDate))`
  - `computedAt` (ISO 8601 datetime string) — when the DB queries that produced this result were executed
  - `cacheHit` (boolean) — whether the response was served from cache

#### Scenario: Endpoint requires authentication

- **GIVEN** an unauthenticated request to `GET /index.php/apps/procest/api/dashboard/kpis`
- **THEN** the response MUST have status code 401
- **AND** no KPI data MUST be returned

#### Scenario: Zero values when no data exists

- **GIVEN** Procest is freshly installed with no cases or tasks
- **WHEN** an authenticated user requests `GET /api/dashboard/kpis`
- **THEN** all integer fields MUST be `0`
- **AND** `statusBreakdown` MUST be `[]`
- **AND** the response MUST have status code 200 (not an error)

#### Scenario: Error resilience

- **GIVEN** the database is temporarily unavailable
- **WHEN** the endpoint is called
- **THEN** the response MUST have status code 500
- **AND** the response body MUST contain an `error` field with a descriptive message
- **AND** no partial or misleading data MUST be returned

---

### Requirement: REQ-KPI-002 — DB-Side Aggregation [V1]

All KPI counts MUST be computed by the database engine using `COUNT(*)`, `GROUP BY`, and filter predicates — never by loading object arrays into PHP and counting them in memory.

**Feature tier**: V1

#### Scenario: Correct count at scale

- **GIVEN** 5,000 cases exist in the Procest register
- **AND** all 5,000 are open (no `endDate`)
- **WHEN** `GET /api/dashboard/kpis` is called
- **THEN** `openCount` MUST be `5000`
- **AND** the response time MUST be under 500ms
- **AND** no `_limit` cap MUST silently truncate the count

#### Scenario: No PHP-side array iteration for counts

- **GIVEN** a code audit of `KpiAggregationService`
- **THEN** it MUST NOT load case or task objects into PHP arrays for the purpose of counting
- **AND** it MUST use `IDBConnection::getQueryBuilder()` with `COUNT(*)` or `selectAlias($qb->func()->count(...), 'cnt')`

---

### Requirement: REQ-KPI-003 — Per-User Cache [V1]

The endpoint MUST cache results per authenticated user with a 60-second TTL via Nextcloud's `ICacheFactory`.

**Feature tier**: V1

#### Scenario: Cache hit within TTL

- **GIVEN** user "jan" called `GET /api/dashboard/kpis` 30 seconds ago
- **WHEN** user "jan" calls the endpoint again
- **THEN** the response MUST be served from cache (no DB queries run)
- **AND** `cacheHit` in the response MUST be `true`
- **AND** the `computedAt` timestamp MUST match the original computation time (not "now")

#### Scenario: Cache miss after TTL expiry

- **GIVEN** user "jan" called the endpoint 61 seconds ago
- **WHEN** user "jan" calls the endpoint again
- **THEN** the system MUST run fresh DB queries
- **AND** `cacheHit` MUST be `false`
- **AND** `computedAt` MUST reflect the new query time

#### Scenario: Cache isolation between users

- **GIVEN** user "jan" has 10 open cases and user "maria" has 5 open cases
- **WHEN** both call `GET /api/dashboard/kpis` simultaneously
- **THEN** "jan" MUST receive `openCount: 10` and "maria" MUST receive `openCount: 5`
- **AND** the cache for "jan" MUST NOT be served to "maria"

#### Scenario: Cache fallback when APCu unavailable

- **GIVEN** APCu is disabled on the server
- **WHEN** `GET /api/dashboard/kpis` is called
- **THEN** the endpoint MUST still return correct data
- **AND** it MUST run DB queries on every request (no error, just uncached)

---

### Requirement: REQ-KPI-004 — Cache Invalidation on Object Changes [V1]

The KPI cache for a user MUST be invalidated (without eager recomputation) when that user performs an object create, update, or delete operation in the Procest register.

**Feature tier**: V1

#### Scenario: Cache invalidated after case creation

- **GIVEN** user "jan"'s KPI cache is populated (`openCount: 10`)
- **WHEN** "jan" creates a new case (triggering `ObjectCreatedEvent`)
- **AND** "jan" calls `GET /api/dashboard/kpis`
- **THEN** the response MUST reflect the newly created case (`openCount: 11`)
- **AND** `cacheHit` MUST be `false` (fresh computation)

#### Scenario: Cache version bump mechanism

- **GIVEN** user "jan"'s current cache version is `3`
- **WHEN** an `ObjectUpdatedEvent` fires for a change made by "jan"
- **THEN** the listener MUST increment the version key to `4`
- **AND** the old version `3` data MAY remain in the cache store until its TTL expires (it will never be served again because the lookup key has changed)

#### Scenario: Other user's cache unaffected

- **GIVEN** user "jan" creates a case
- **THEN** user "maria"'s KPI cache version MUST NOT be bumped
- **AND** maria's next `GET /api/dashboard/kpis` MAY still return cached data

---

### Requirement: REQ-KPI — 005a: Average Processing Days [V1]

The endpoint MUST compute `avgProcessingDays` as the SQL average of date differences between `endDate` and `startDate` for cases whose `endDate` falls in the current calendar month.

**Feature tier**: V1

#### Scenario: avgProcessingDays for current month

- **GIVEN** 4 cases were completed this calendar month with processing durations 3, 5, 7, and 9 days
- **WHEN** `GET /api/dashboard/kpis` is called
- **THEN** `avgProcessingDays` MUST equal `6.0` (the SQL `AVG(DATEDIFF(endDate, startDate))` result)
- **AND** it MUST be computed by the database (`AVG(DATEDIFF(endDate, startDate))`), not by PHP-side iteration

#### Scenario: No completed cases this month

- **GIVEN** no cases have been completed in the current calendar month
- **WHEN** the endpoint is called
- **THEN** `avgProcessingDays` MUST be `null` (not `0`, since no data is different from "0 days")

#### Scenario: avgProcessingDays uses endDate IS NOT NULL filter

- **GIVEN** open cases (with `endDate IS NULL`) exist alongside completed cases
- **THEN** open cases MUST be excluded from the AVG computation
- **AND** only cases with both `startDate IS NOT NULL` and `endDate IS NOT NULL` MUST be included

---

### Requirement: REQ-KPI-005 — Permission-Scoped Task Counts [V1]

Task KPI counts (`taskCount`, `tasksDueToday`) MUST be scoped to the requesting user's assigned tasks. Case counts are register-wide for v1 (matching current behaviour).

**Feature tier**: V1

#### Scenario: Task count scoped to assignee

- **GIVEN** user "jan" has 3 tasks assigned and user "maria" has 7 tasks assigned
- **WHEN** "jan" calls `GET /api/dashboard/kpis`
- **THEN** `taskCount` MUST be `3` (not `10`)

#### Scenario: Case counts are register-wide for v1

- **GIVEN** user "jan" views the dashboard
- **THEN** `openCount`, `overdueCount`, `completedCount`, and `newToday` reflect all cases in the Procest register, not just Jan's assigned cases
- **AND** this matches the current client-side behaviour where `fetchCollection('case')` returns all accessible cases

#### Scenario: statusBreakdown is register-wide

- **GIVEN** the register has 20 open cases with 3 different statuses
- **WHEN** any authenticated user calls `GET /api/dashboard/kpis`
- **THEN** `statusBreakdown` MUST list all 3 statuses with their respective counts

---

## Performance Requirements (ADDED)

- `GET /api/dashboard/kpis` MUST respond in < 100ms on a cache hit.
- `GET /api/dashboard/kpis` MUST respond in < 500ms on a cache miss for up to 10,000 objects.
- Response time MAY exceed 500ms for > 10,000 objects; adding a generated column index on the status JSON field is the recommended mitigation if this threshold is reached in production.
- The endpoint MUST NOT perform N+1 queries — all counts MUST be computed in a fixed number of SQL queries (≤ 8 for v1, including the AVG query).

## Workflow Invariants (NORMATIVE assumption)

- **`endDate` invariant**: A case is considered "open" iff `endDate IS NULL`. The Procest workflow engine MUST set `endDate` whenever a case transitions to a final status (`statusType.isFinal === true`). If a deployment can produce a case in a final status without an `endDate`, the open-case proxy MUST be re-validated by the operator. The `KpiAggregationService` PHP docblock MUST document this invariant. A verification task in tasks.md MUST cover an automated assertion that no `endDate IS NULL` case has a `currentStatus` whose `statusType.isFinal` is `true`.

## Accepted Risks (NORMATIVE)

- **Row-level ACL not applied to case counts in v1.** `openCount`, `overdueCount`, `completedCount`, `newToday`, `statusBreakdown`, and `avgProcessingDays` count every case the user can access at the register-read level. If row-level ACL ever becomes enabled in Procest, totals will over-count relative to what the user can actually see. This matches today's client-side `fetchCollection('case')` behaviour, so v1 introduces no regression. A follow-up change is required if row-level ACL lands in Procest.
