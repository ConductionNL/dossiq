---
kind: code
---

# Proposal: Procest Adopts OpenRegister AppHost (Observability + Boilerplate)

## Problem

Procest hand-writes the full fleet boilerplate that the OpenRegister AppHost now provides centrally (`apphost-observability-engine` + `apphost-boilerplate-controllers`):

- `lib/Controller/HealthController.php` (189 lines) and `lib/Controller/MetricsController.php` (462 lines) — drifted copies of the petstore skeleton, with procest-local APCu caching and hand-rolled `JSON_EXTRACT` SQL because portable JSON aggregation is hard in a leaf app (a problem OR's query layer already solves).
- The Dashboard/Preferences/Settings controller trio, `SettingsService`, `AdminSettings`/`SettingsSection`, `InitializeSettings`, and `DeepLinkRegistrationListener` — all near-identical to the 15–18 fleet copies inventoried on 2026-06-12.

Worse, the audit of the metrics SQL found a **real correctness bug**: case/task schemas are resolved by `s.title LIKE '%aak%'` / `'%taak%'`. Procest's actual case schema is titled **"Case"** and its task schema **"Task"** — neither matches. Today:

- `procest_cases_total` / `cases_overdue_total` / `cases_created_today` count objects of `zaaktypeInformatieobjecttype`, `wmoZaak`, `jeugdwetZaak`, and `participatiewetZaak` (the only schemas whose titles contain "aak") — **not actual cases**.
- `procest_tasks_total` / `tasks_overdue_total` match **no schema at all** and always emit empty/zero.

## Proposed Change

Replace the hand-written observability controllers with an `observability` block in `src/manifest.json` executed by the AppHost engine, and replace the boilerplate plumbing with `AppHost\Bootstrap::register()` + `Routes::standard()` aliases. Route names, URLs, verbs, and response shapes stay identical.

### Health descriptor (1:1 with today, zero behaviour change)

| Check id | Type | Severity | Today's implementation |
|---|---|---|---|
| `database` | `database` | `critical` | `SELECT 1` via QueryBuilder |
| `openregister` | `appEnabled` (`app: openregister`) | `critical` | `IAppManager::isEnabledForUser('openregister')` |
| `filesystem` | `filesystem` | `degraded` | temp-file write+unlink |

`statusCodePolicy: "adr006"` (default) reproduces today's 200/503 contract: any critical failure → `status=error` + HTTP 503; filesystem-only failure → `status=degraded` + HTTP 200.

### Metrics descriptors (resolved slugs, engine cache)

Register slug `procest`; schema slugs resolved from `lib/Settings/procest_register.json`: case schema = **`case`**, task schema = **`task`** (replacing the broken `title LIKE` patterns above).

| Metric | Descriptor source | cacheTtl | Replaces |
|---|---|---|---|
| `procest_cases_total{status,case_type}` | `objectCount` register `procest`, schema `case`, `groupBy: ["status","caseType"]` | 30 | `getCaseCounts()` (APCu 30s) |
| `procest_cases_overdue_total` | `objectCount` schema `case`, `filter: {"uiterlijkeEinddatumAfdoening": {"lt": "now"}}` | 60 | `getOverdueCasesCount()` (APCu 60s) |
| `procest_cases_created_today` | `objectCount` schema `case`, `filter: {"startDate": {"gte": "today"}}` | 30 | `getCasesCreatedTodayCount()` (APCu 30s) |
| `procest_tasks_total{status}` | `objectCount` schema `task`, `groupBy: ["status"]` | 30 | `getTaskCounts()` (APCu 30s) |
| `procest_tasks_overdue_total` | `objectCount` schema `task`, `filter: {"deadline": {"lt": "now"}}` | 60 | `getOverdueTasksCount()` (APCu 60s) |
| `procest_info`, `procest_up` | implicit (never declared) | — | hand-written info/up blocks |

Metric names, types, HELP texts, and label sets stay identical (`caseType` JSON field continues to surface as the `case_type` label). **Metric values change deliberately** where today's title-pattern bug miscounts — this is a documented fix, not a regression (see Verification).

**APCu → engine cache (deliberate infra simplification)**: the controller-local `apcu_fetch`/`apcu_store` caching (30s/60s TTLs) is dropped in favour of the engine's per-metric `cacheTtl` via `ICacheFactory` distributed cache. Same TTLs, but cache is now shared across PHP workers/nodes instead of per-process APCu — strictly equal or better scrape cost; no APCu dependency left in procest.

### Boilerplate deletions

| File | Lines | Disposition |
|---|---|---|
| `lib/Controller/HealthController.php` | 189 | **Delete**; route `health#index` aliased to `GenericHealthController` |
| `lib/Controller/MetricsController.php` | 462 | **Delete**; route `metrics#index` aliased to `GenericMetricsController` |
| `lib/Controller/DashboardController.php` | 75 | **Delete**; `dashboard#page` + catch-all aliased to `GenericDashboardController` |
| `lib/Controller/PreferencesController.php` | 156 | **Delete**; aliased to `GenericPreferencesController` (existing user-pref keys keep resolving) |
| `lib/Controller/SettingsController.php` | 184 | **Delete**; aliased to `GenericSettingsController` |
| `lib/Service/SettingsService.php` | 939 | **Replace** with `AppHostSettingsService`; procest's `SLUG_TO_CONFIG_KEY` map and register.d fragment merge move to Bootstrap options / the generic register.d support; any genuinely procest-specific residue stays as a thin subclass |
| `lib/Settings/AdminSettings.php` | 115 | **Delete**; `GenericAdminSettings` (IDelegatedSettings, #299 pattern) registered via Bootstrap; one-line stub only if NC `<settings>` demands an app-namespace class |
| `lib/Sections/SettingsSection.php` | 89 | **Delete**; `GenericSettingsSection` (same stub caveat) |
| `lib/Repair/InitializeSettings.php` | 121 | **Shrink** to `class InitializeSettings extends GenericInitializeSettings {}` — info.xml `<repair-steps>` requires an app-namespace class |
| `lib/Listener/DeepLinkRegistrationListener.php` | 75 | **Delete**; deep-link patterns move to the manifest `deepLinks` block |

~1,400 lines deleted outright + ~900 replaced by the generic service. **Not deleted**: `Application.php` (procest registers 20+ domain listeners, 7 middlewares, adapter aliases, dashboard widgets, MCP provider — it shrinks by swapping the boilerplate registrations for one `Bootstrap::register()` call) and `appinfo/routes.php` (becomes `Routes::standard($extra)` with the ~250 domain routes in `$extra`). Domain seed repair steps (`SeedLegesData`, `SeedBezwaarBeroepData`, …) and domain listeners are out of scope.

## Verification

- Baseline capture of `/api/health` + `/api/metrics` before any change; post-adoption diff asserts identical metric **names, types, HELP, and label sets** and identical health shape.
- **Values for the case/task metrics are expected to differ** (title-LIKE bug fix). Equivalence of the date-token semantics (`lt: "now"`, `gte: "today"`) vs the old SQL (`< 'Y-m-d'` string compare with NULL exclusion; `LIKE 'Y-m-d%'` prefix match) is proven by re-running the old SQL re-pointed at schema `case`/`task` on a seeded instance and comparing counts — including a future-dated `startDate` seed to flush out any `gte today` vs prefix-LIKE divergence.
- OR's AppHost Newman contract collection + procest's existing Newman collection and Playwright e2e suite.

## Impact

- **Modified**: `src/manifest.json` (observability + deepLinks blocks), `lib/AppInfo/Application.php`, `appinfo/routes.php`, `appinfo/info.xml` (settings/repair-step class names if stubs change), docs.
- **Deleted**: 8 files per the table; `InitializeSettings` shrinks to a stub.
- **Risk**: procest's metrics values change where the old code was miscounting — release notes + docs flag this. Engine regressions are guarded by OR's contract collection.
- **Delivery**: procest is on the racing-PR list — this change ships via Codeberg PR only, never direct push to `development`.

## Dependencies

Chained on OpenRegister: `apphost-observability-engine` (engine + generic health/metrics controllers), `apphost-boilerplate-controllers` (Bootstrap, Routes, generic plumbing). ADR-040 (hydra) defines the manifest block contract; ADR-022 is the architectural basis.
