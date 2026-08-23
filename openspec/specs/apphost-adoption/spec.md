# apphost-adoption Specification

## Purpose
TBD - created by archiving change adopt-apphost. Update Purpose after archive.
## Requirements
### Requirement: Declarative Health Endpoint

Dossiq SHALL serve `GET /apps/dossiq/api/health` through the AppHost engine from manifest descriptors — checks `database` (critical), `openregister` appEnabled (critical), `filesystem` (degraded) — under `statusCodePolicy: adr006`, preserving today's response shape and 200/503 contract.

#### Scenario: Healthy instance

- **GIVEN** a running instance with the database reachable and openregister enabled
- **WHEN** `GET /apps/dossiq/api/health` is called anonymously
- **THEN** the response MUST be HTTP 200 with `status = "ok"` and `checks.database`, `checks.openregister`, `checks.filesystem` all `"ok"`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Critical dependency down

- **GIVEN** the openregister app is disabled
- **WHEN** `GET /apps/dossiq/api/health` is called anonymously
- **THEN** the response MUST be HTTP 503 with `status = "error"` and `checks.openregister` reporting a failure, while a filesystem-only failure MUST instead yield HTTP 200 with `status = "degraded"`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Declarative Metrics Endpoint

Dossiq SHALL serve `GET /apps/dossiq/api/metrics` (admin-only, Prometheus text 0.0.4) through the AppHost engine: `cases_total{status,case_type}`, `cases_overdue_total`, `cases_created_today`, `tasks_total{status}`, `tasks_overdue_total` as `objectCount` descriptors on register `dossiq` schemas `case`/`task`, plus implicit `dossiq_info`/`dossiq_up`. Metric names, types, HELP texts, and label sets MUST be identical to the pre-adoption output; per-metric `cacheTtl` (30s/60s) replaces the controller-local APCu cache.

#### Scenario: Metrics exposition parity

- **GIVEN** a seeded instance with case and task objects
- **WHEN** `GET /apps/dossiq/api/metrics` is called by an admin
- **THEN** the output MUST contain `dossiq_info`, `dossiq_up`, `dossiq_cases_total{status,case_type}`, `dossiq_cases_overdue_total`, `dossiq_cases_created_today`, `dossiq_tasks_total{status}`, `dossiq_tasks_overdue_total` with the same names, types, HELP texts, and label sets as the pre-adoption baseline, in Prometheus text format 0.0.4
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Corrected schema resolution

- **GIVEN** a seeded instance with objects in schema `case` (title "Case"), schema `task` (title "Task"), and schema `wmoZaak` (title "WMO Zaak")
- **WHEN** `GET /apps/dossiq/api/metrics` is called by an admin
- **THEN** `dossiq_cases_total` MUST count only schema `case` objects and `dossiq_tasks_total` only schema `task` objects — the pre-adoption `title LIKE '%aak%'`/`'%taak%'` miscount (which matched `wmoZaak` and matched no task schema at all) MUST NOT be reproduced
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Date-Token Filter Parity

The engine date tokens used by dossiq's descriptors — `{"lt": "now"}` on `uiterlijkeEinddatumAfdoening`/`deadline` and `{"gte": "today"}` on `startDate` — SHALL reproduce the semantics of the replaced SQL: overdue = date value present AND strictly before today's date (objects with a missing/NULL date field are never overdue); created-today = `startDate` falls on the current calendar day.

#### Scenario: Date-token filter parity on a seeded instance

- **GIVEN** a seeded instance with cases whose `uiterlijkeEinddatumAfdoening` is yesterday, today, and absent; cases whose `startDate` is yesterday, today, and a future date; and a task with `deadline` yesterday plus a task without `deadline`
- **WHEN** `GET /apps/dossiq/api/metrics` is called by an admin
- **THEN** `dossiq_cases_overdue_total` MUST equal the old SQL count (`< today`, NULLs excluded) re-pointed at schema `case`, `dossiq_tasks_overdue_total` MUST equal 1, and `dossiq_cases_created_today` MUST count exactly the cases whose `startDate` is on the current calendar day — a future-dated `startDate` MUST NOT inflate the count
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Boilerplate Replacement With Endpoint Parity

Dossiq SHALL adopt the AppHost generics via `Bootstrap::register()` and `Routes::standard($extra)` for every plumbing concern that is mechanically equivalent to the engine — deleting its hand-written `HealthController`, `MetricsController`, `PreferencesController`, and `DeepLinkRegistrationListener`, and serving the SPA page + catch-all through `GenericDashboardController` — with route names, URLs, verbs, response shapes, and stored preference keys unchanged, and all domain routes/registrations preserved.

Dossiq's Settings cluster — `SettingsController`, `SettingsService`, `AdminSettings`, `SettingsSection`, and the `InitializeSettings` repair step — SHALL be RETAINED as bespoke (re-aliased to the concrete dossiq classes after `Bootstrap::register()`) because it is entangled beyond the generic contract: the `/api/settings` response envelope (`{config, openRegisters, isAdmin}`) differs from the generic flat shape and is consumed across the dossiq frontend; `SettingsService` is injected at ~180 sites and provides domain helpers (`getObjectService`, `getKccConfigValue`, `getConfigValue`, secret redaction, the `register.d/*.json` fragment merge, and `reconcileSchemaConfig`) the generic does not; and `InitializeSettings` depends on that reconcile to provision the schema-config keys the WorkflowBoard needs. The dossiq `DashboardController` SHALL extend `GenericDashboardController`, inheriting the SPA page/catch-all and retaining only its two PWA-asset endpoints (`serviceWorker`, `webManifest`).

#### Scenario: Dashboard shell served by the generic controller

- **GIVEN** a logged-in user
- **WHEN** the user opens `/apps/dossiq/` or any deep link covered by the catch-all route
- **THEN** the dossiq SPA MUST load and render exactly as before adoption, including the chunk-loading order from `templates/index.php`

#### Scenario: Preferences endpoint served by the generic controller

- **GIVEN** a user with a preference previously stored via the old `PreferencesController`
- **WHEN** `GET /apps/dossiq/api/preferences/{key}` is called
- **THEN** the stored value MUST resolve under the same `pref_` key namespace, served by `GenericPreferencesController`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Settings endpoint contract retained bespoke

- **GIVEN** the dossiq frontend reading `GET /apps/dossiq/api/settings`
- **WHEN** the endpoint is called
- **THEN** the response MUST keep the pre-adoption `{config, openRegisters, isAdmin}` envelope served by the retained dossiq `SettingsController` + `SettingsService` — the engine generics MUST NOT be substituted, since their flat shape would break the frontend
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Repair step still imports the register on enable

- **GIVEN** a fresh instance with openregister enabled
- **WHEN** `occ app:enable dossiq` runs
- **THEN** the retained dossiq `InitializeSettings` repair step MUST import `dossiq_register.json` plus all `register.d/*.json` fragments through ConfigurationService AND run `reconcileSchemaConfig()`, exactly as before adoption — the generic `GenericInitializeSettings` stub MUST NOT replace it, since it does not perform the register.d merge or the schema reconcile
- @e2e exclude install-time occ behaviour — covered by PHPUnit + the install smoke check, not browser UI

