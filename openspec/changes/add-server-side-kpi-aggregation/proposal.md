# Proposal: add-server-side-kpi-aggregation

## Summary

Replace client-side KPI aggregation on the Procest dashboard with a dedicated backend endpoint (`GET /api/dashboard/kpis`) that computes counts and sums via database queries. Currently the dashboard fetches up to 1,000 case objects and 100 task objects into the browser just to count them — this is O(n) network transfer for what should be O(1) database work, and it silently breaks when case volumes exceed the fetch limit.

## Motivation

`Dashboard.vue` calls `objectStore.fetchCollection('case', { _limit: 1000 })`, `fetchCollection('statusType', { _limit: 500 })`, and `fetchCollection('task', { _limit: 100 })` in parallel, then pipes the raw arrays through `computeKpis()`, `computeSlaCompliance()`, `aggregateByStatus()`, and the signalering helpers in `src/utils/dashboardHelpers.js`. A municipality with 2,000 open cases silently shows wrong counts because the fetch is capped at 1,000. The pattern is documented as acceptable in the current spec (`Performance` note in REQ-DASH-010) but it is not acceptable at production scale.

The existing `MetricsController` already demonstrates that DB-side aggregation via `IDBConnection` / `createFunction("JSON_EXTRACT...")` is feasible in this codebase and produces correct counts at any volume.

## Affected Projects

- [x] Project: `procest` — New `KpiController`, new `KpiAggregationService`, route registration, per-user cache

## Scope

### In Scope (V1)

- **REQ-KPI-001**: `GET /api/dashboard/kpis` endpoint returning a stable JSON object with all headline KPI values
- **REQ-KPI-002**: DB-side aggregation using `COUNT(*)` / `SUM()` / `GROUP BY` — no PHP-side array iteration
- **REQ-KPI-003**: Per-user result cache (60s TTL) via Nextcloud `ICacheFactory` with version-bump invalidation
- **REQ-KPI-004**: Cache invalidation on object change events (`ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`)
- **REQ-KPI-005**: Permission-scoped counts — only counts objects the requesting user can read (user-id keyed queries)
- Unit tests for `KpiAggregationService` and `KpiController`

### Out of Scope (follow-up changes)

- Frontend migration of `KpiCards.vue` from `fetchCollection().then(aggregate)` to `GET /api/dashboard/kpis` — tracked as follow-up change `migrate-kpi-cards-to-aggregation-endpoint`
- Cross-user / org-wide aggregations (per-user only for v1)
- Custom / configurable KPIs (later change)
- Live push of KPI updates to UI — the `add-live-updates-plugin` change in nextcloud-vue handles re-poll/subscribe when cache invalidates
- SLA compliance computation (remains client-side until separate change; the `avgProcessingDays` field is excluded from the v1 KPI response because it requires per-case date arithmetic that needs a follow-up design decision — see DEFERRED_QUESTIONS)

## Approach

1. Add `KpiAggregationService` (`lib/Service/KpiAggregationService.php`) that runs DB-side `COUNT`/`GROUP BY` queries via `IDBConnection`. Follows the pattern already established in `MetricsController`.
2. Add `KpiController` (`lib/Controller/KpiController.php`) — `@NoAdminRequired`, returns `JSONResponse`. Wraps service call and applies per-user `ICacheFactory` cache with 60s TTL.
3. Register route `GET /api/dashboard/kpis` in `appinfo/routes.php` before the SPA catch-all.
4. Register `KpiCacheInvalidationListener` (`lib/Listener/KpiCacheInvalidationListener.php`) on the three OpenRegister object events to bump the user's cache version key — no eager recomputation.
5. Unit tests for service and controller.
