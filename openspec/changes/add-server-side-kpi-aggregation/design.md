# Design: add-server-side-kpi-aggregation

## Architecture

No new database tables needed — Procest owns no tables (thin-client pattern; data lives in OpenRegister's `openregister_objects`). The new service queries OpenRegister tables directly using `IDBConnection`, exactly as `MetricsController` does today.

Three new PHP files, one route addition, no seed data, no schema changes.

## Response Shape

`GET /index.php/apps/procest/api/dashboard/kpis` returns HTTP 200 with:

```json
{
  "openCount": 42,
  "newToday": 3,
  "overdueCount": 7,
  "completedCount": 12,
  "taskCount": 5,
  "tasksDueToday": 2,
  "statusBreakdown": [
    { "status": "open", "count": 20 },
    { "status": "in-behandeling", "count": 15 },
    { "status": "besluitvorming", "count": 7 }
  ],
  "computedAt": "2026-05-09T14:30:00+00:00",
  "cacheHit": false
}
```

All fields are always present. Integer fields default to `0` on error. `statusBreakdown` defaults to `[]`. The `cacheHit` flag is informational (for debugging / monitoring). The `computedAt` field reflects when the DB queries ran (not when the response was served from cache — the cached response preserves the original `computedAt`).

**Included in v1**: `avgProcessingDays` — `AVG(DATEDIFF(endDate, startDate))` filtered to cases completed in the current calendar month. Returns `null` (not `0`) when no completed cases exist; excludes cases missing either `startDate` or `endDate`.

**Excluded from v1**: `slaCompliance` (deferred to a separate change — SLA per-case logic is more involved than a flat AVG and benefits from its own design).

**Workflow invariant relied on**: a case is "open" iff `endDate IS NULL`. The Procest workflow engine MUST set `endDate` whenever a case transitions to a final status. `KpiAggregationService` documents this in its class docblock; a verification task asserts no `endDate IS NULL` case has a `currentStatus` whose `statusType.isFinal` is `true`. If a deployment violates the invariant, the open-case proxy must be re-validated by the operator.

**Accepted risk — case counts are register-wide for v1**: case-level counts (`openCount`, `overdueCount`, `completedCount`, `newToday`, `statusBreakdown`, `avgProcessingDays`) are not filtered by row-level ACL. They count every case the requesting user can access at the register-read level. This matches today's client-side `fetchCollection('case')` behaviour, so no regression is introduced. If Procest ever enables row-level ACL, a follow-up change is required to apply the same filter at the SQL level (likely via an OR-provided ACL parameter).

## File Changes

| File | Change |
|------|--------|
| `lib/Service/KpiAggregationService.php` | New — DB-side aggregation queries |
| `lib/Controller/KpiController.php` | New — `GET /api/dashboard/kpis`, cache wrapper |
| `lib/Listener/KpiCacheInvalidationListener.php` | New — listens to OR object events, bumps cache version |
| `appinfo/routes.php` | Add `kpi#index` route before SPA catch-all |
| `lib/AppInfo/Application.php` | Register listener for 3 OpenRegister events |
| `tests/Unit/Service/KpiAggregationServiceTest.php` | New — unit tests |
| `tests/Unit/Controller/KpiControllerTest.php` | New — unit tests |

## KpiAggregationService

```php
// Signature (illustrative — not normative)
class KpiAggregationService {
    public function __construct(
        private IDBConnection $db,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {}

    public function computeKpis(string $userId): array
    // Returns the array matching the JSON shape above (minus cacheHit/computedAt).
}
```

Queries follow the `MetricsController` pattern: `JSON_EXTRACT(o.object, '$.fieldName')` for JSON fields, `INNER JOIN openregister_schemas s ON o.schema = s.id` + `WHERE s.title LIKE '%zaak%'` to scope to case objects, and `WHERE s.title LIKE '%taak%'` for task objects.

Permission scoping for v1: The OpenRegister `openregister_objects` table has a `register` column. Procest cases live in the register configured via `ConfigurationService`. Scoping by `userId` means filtering `JSON_EXTRACT(o.object, '$.assignee') = :userId` for tasks. For cases, OpenRegister's built-in ACL lives above the DB layer and cannot be replicated cheaply in raw SQL for v1. **Decision**: Cases counts are unscoped at DB level (counts all cases in the Procest register) — acceptable for v1 since Procest currently has no row-level ACL beyond what OR provides. Task counts are scoped to `assignee == userId` because the KPI says "My Tasks". This matches the current client-side behaviour. Documented in DEFERRED_QUESTIONS.

### Query Breakdown

| KPI field | Query approach |
|-----------|----------------|
| `openCount` | `COUNT(*)` WHERE status JSON not in final-status set. Status finality is not stored in the objects table directly — see DEFERRED_QUESTIONS for how to handle this. For v1: count cases where `JSON_EXTRACT(o.object, '$.endDate')` IS NULL or empty string (proxy for "not yet closed"). |
| `newToday` | `COUNT(*)` WHERE `JSON_EXTRACT(o.object, '$.startDate')` starts with today's date string |
| `overdueCount` | `COUNT(*)` WHERE `JSON_EXTRACT(o.object, '$.deadline')` < NOW() AND endDate IS NULL |
| `completedCount` | `COUNT(*)` WHERE `JSON_EXTRACT(o.object, '$.endDate')` starts with current month prefix (`YYYY-MM`) |
| `taskCount` | `COUNT(*)` tasks WHERE `assignee = userId` AND `status IN ('available', 'active')` |
| `tasksDueToday` | `COUNT(*)` tasks WHERE `assignee = userId` AND `status IN ('available', 'active')` AND `dueDate` starts with today |
| `statusBreakdown` | `GROUP BY JSON_EXTRACT(o.object, '$.status')` on open cases, returns array of `{status, count}` |

All queries use parameterised values (`createNamedParameter`) — no string interpolation.

## Caching

### Strategy: per-user cache with version bump

```
cache key for data:    "procest_kpis_{userId}_v{version}"
cache key for version: "procest_kpis_{userId}_ver"
```

On `GET /api/dashboard/kpis`:
1. Read version from `ICache::get("procest_kpis_{userId}_ver")` (default `1` if absent).
2. Read data from `ICache::get("procest_kpis_{userId}_v{version}")`.
3. Cache hit → return JSON with `cacheHit: true`.
4. Cache miss → run DB queries, store result with 60s TTL, return with `cacheHit: false`.

On `ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectDeletedEvent`:
1. Extract `userId` from the event (the acting user).
2. Increment `"procest_kpis_{userId}_ver"` by 1 (stored with no TTL — a persistent counter is fine; it's tiny).
3. **Do not recompute** — next `GET` will find a cache miss on the new version key and compute fresh.

This avoids thundering-herd on write (no eager recomputation) while guaranteeing the next read after any write returns fresh data.

### Cache backend

`ICacheFactory::createLocal()` — this is the in-process APCu cache available to every Nextcloud instance. It is not shared across workers but is sufficient for dashboard KPIs (slight inconsistency between PHP workers is acceptable for 60s data). Falls back gracefully if APCu is unavailable (just runs DB queries every time).

## Performance Targets

| Scenario | Target | Basis |
|----------|--------|-------|
| Cache hit response time | < 100ms | APCu lookup + JSON encode is sub-millisecond |
| Cache miss response time (10k objects) | < 500ms | 7 `COUNT(*)` queries on indexed JSON columns |
| Cache miss response time (100k objects) | < 2s | Acceptable tail; index on `schema_id` + JSON index on `status`/`endDate` recommended if exceeded |

The `openregister_objects` table already has an index on `schema` (from OpenRegister's own mapper). JSON field extraction (`JSON_EXTRACT`) without a generated column index is O(n) per-query but fast in practice for counts — the MetricsController demonstrates this. No additional DB schema changes are required for v1.

## Seed Data

This change introduces no new schemas and requires no seed data.

## Security

- `@NoAdminRequired` — any authenticated Nextcloud user can query their own KPIs.
- No `@NoCSRFRequired` — the endpoint is called from the frontend with the Nextcloud request token present.
- The response includes only aggregate counts, never object content. No PII is exposed.
- Cache is keyed per-user — one user cannot read another user's cached KPIs.

## Follow-up Changes

- `migrate-kpi-cards-to-aggregation-endpoint`: Update `Dashboard.vue` to call `GET /api/dashboard/kpis` instead of four `fetchCollection()` calls. The component `KpiCards.vue` props interface stays identical; only the data source changes.
