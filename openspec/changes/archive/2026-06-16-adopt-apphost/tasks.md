# Tasks: Procest Adopts OpenRegister AppHost

> **Implementation note (2026-06-16, build/adopt-apphost-2026-06-16).**
> Adopted the genuinely-mechanical halves: observability (Health+Metrics via
> the manifest `observability` block + Generic controllers), `PreferencesController`
> (byte-equivalent → deleted), `DeepLinkRegistrationListener` (→ manifest
> `deepLinks` block), and the SPA page/catch-all (procest `DashboardController`
> now extends `GenericDashboardController`, keeping only its two PWA endpoints).
> Wired via `Bootstrap::register()` + `Routes::standard($extra)`.
>
> **KEPT bespoke (re-aliased to concrete procest classes after Bootstrap):**
> `SettingsController` + `SettingsService` + `AdminSettings` + `SettingsSection`
> + `InitializeSettings`. They are entangled beyond the generic contract —
> (a) the `/api/settings` response envelope is `{config, openRegisters, isAdmin}`
> (the procest frontend reads `data.config` / `data.openRegisters`), which differs
> from the generic flat `{register, openregisters, isAdmin}` shape and would break
> the frontend; (b) `SettingsService` is injected at ~180 sites and provides domain
> helpers (`getObjectService`, `getKccConfigValue`, `getConfigValue`, secret
> redaction, the `register.d/*.json` fragment merge, `reconcileSchemaConfig`) absent
> from the generic; (c) `InitializeSettings` depends on `reconcileSchemaConfig()` to
> provision the schema-config keys the WorkflowBoard needs. The spec
> (`specs/apphost-adoption/spec.md`, Requirement "Boilerplate Replacement With
> Endpoint Parity") was amended to record this split.
>
> Tasks 2.4/2.5 (replace SettingsService / shrink InitializeSettings) are therefore
> intentionally NOT done — see the bespoke-retention rationale above.

## 0. Baseline

- [ ] 0.1 Capture baseline on a seeded dev instance: `curl /apps/procest/api/health` JSON + `curl /apps/procest/api/metrics` Prometheus text; store as fixtures for the parity diff
- [ ] 0.2 Record the old SQL counts re-pointed at the **correct** schemas (`case`, `task`) alongside the broken `title LIKE '%aak%'`/`'%taak%'` counts — this is the expected-value baseline for the post-adoption values (the title-LIKE numbers are the documented bug)

## 1. Manifest observability block

- [ ] 1.1 Add to `src/manifest.json`:

```json
"observability": {
  "health": {
    "statusCodePolicy": "adr006",
    "checks": [
      { "id": "database",     "type": "database" },
      { "id": "openregister", "type": "appEnabled", "app": "openregister", "severity": "critical" },
      { "id": "filesystem",   "type": "filesystem", "severity": "degraded" }
    ]
  },
  "metrics": [
    { "name": "cases_total", "type": "gauge", "help": "Total cases by status and case_type", "cacheTtl": 30,
      "source": { "kind": "objectCount", "register": "procest", "schema": "case",
                  "groupBy": ["status", "caseType"] } },
    { "name": "cases_overdue_total", "type": "gauge", "help": "Cases past their deadline", "cacheTtl": 60,
      "source": { "kind": "objectCount", "register": "procest", "schema": "case",
                  "filter": { "uiterlijkeEinddatumAfdoening": { "lt": "now" } } } },
    { "name": "cases_created_today", "type": "gauge", "help": "Cases created today", "cacheTtl": 30,
      "source": { "kind": "objectCount", "register": "procest", "schema": "case",
                  "filter": { "startDate": { "gte": "today" } } } },
    { "name": "tasks_total", "type": "gauge", "help": "Total tasks by status", "cacheTtl": 30,
      "source": { "kind": "objectCount", "register": "procest", "schema": "task",
                  "groupBy": ["status"] } },
    { "name": "tasks_overdue_total", "type": "gauge", "help": "Tasks past their deadline", "cacheTtl": 60,
      "source": { "kind": "objectCount", "register": "procest", "schema": "task",
                  "filter": { "deadline": { "lt": "now" } } } }
  ]
}
```

- [ ] 1.2 Add the `deepLinks` block (patterns currently hardcoded in `DeepLinkRegistrationListener`)
- [ ] 1.3 Validate via ManifestService diagnostics (no errors; `case`/`task`/`procest` slugs resolve)

## 2. Wiring and deletions

- [ ] 2.1 Replace the boilerplate registrations in `lib/AppInfo/Application.php` with `\OCA\OpenRegister\AppHost\Bootstrap::register($context, self::APP_ID, [...])`; keep all domain registrations (listeners, middlewares, adapter aliases, widgets, MCP provider) untouched
- [ ] 2.2 Rewrite `appinfo/routes.php` as `return \OCA\OpenRegister\AppHost\Routes::standard($extra)` with all ~250 domain routes in `$extra`; route names (`dashboard#page`, `settings#index/create/load`, `preferences#getPreference/setPreference`, `health#index`, `metrics#index`, catch-all) and URLs unchanged
- [ ] 2.3 Delete: `lib/Controller/HealthController.php`, `lib/Controller/MetricsController.php`, `lib/Controller/DashboardController.php`, `lib/Controller/PreferencesController.php`, `lib/Controller/SettingsController.php`, `lib/Settings/AdminSettings.php`, `lib/Sections/SettingsSection.php`, `lib/Listener/DeepLinkRegistrationListener.php`
- [ ] 2.4 Replace `lib/Service/SettingsService.php` with the AppHost generic: move `SLUG_TO_CONFIG_KEY` + register.d fragment merge into Bootstrap options / generic support; keep any procest-only residue as a thin subclass; sweep all internal callers (`SettingsService` is injected widely)
- [ ] 2.5 Shrink `lib/Repair/InitializeSettings.php` to `class InitializeSettings extends GenericInitializeSettings {}` (info.xml `<repair-steps>` needs the app-namespace class); update `appinfo/info.xml` `<settings>` entries if stubs are needed there too
- [ ] 2.6 Sweep references: unit tests, psalm/phpstan baselines, `@spec` tags, docs links to the deleted classes

## 3. Parity verification

- [ ] 3.1 Diff post-adoption `/api/metrics` vs the 0.1 baseline: metric **names, types, HELP texts, and label sets identical** (incl. `caseType` JSON field → `case_type` label); health JSON shape and 200/503 policy identical; document the intentional **value** deltas from the title-LIKE fix (cases_* now count schema `case`, tasks_* now non-empty)
- [ ] 3.2 **Date-token semantics equivalence, explicitly tested**: on a seeded instance with cases dated past / today / future and a NULL-deadline task, assert `cases_overdue_total` and `cases_created_today` match the old SQL (string `<` compare with NULL exclusion; `LIKE 'Y-m-d%'` prefix match) re-pointed at schema `case`. The future-dated `startDate` seed specifically guards the `gte: "today"` vs prefix-LIKE edge — escalate any divergence to `apphost-observability-engine` before merge
- [ ] 3.3 Confirm engine `cacheTtl` (30s/60s via ICacheFactory) serves cached samples within TTL — APCu removal leaves no per-request aggregation regression
- [ ] 3.4 OR AppHost Newman contract collection green against procest; procest's `tests/integration/procest.postman_collection.json` updated for settings/preferences endpoints and green
- [ ] 3.5 Existing Playwright e2e suite green (dashboard loads via GenericDashboardController, admin settings section renders); reference the dashboard-shell scenario from the existing dashboard e2e spec
- [ ] 3.6 Existing PHPUnit suite green; tests for deleted controllers removed/ported

## 4. Docs

- [ ] 4.1 Update `docs/Technical/architecture.md` (observability section): procest health/metrics now run declaratively on the OR AppHost; document the metrics-value correction (title-LIKE bug) for operators with dashboards/alerts on `procest_cases_*`/`procest_tasks_*`

## 5. Quality gates and delivery

- [ ] 5.1 `composer check:strict` + all hydra gates green (incl. gate-22 manifest validation, gate-16 `@spec`, gate-19 e2e annotations); fix any pre-existing issues encountered
- [ ] 5.2 **Deliver via Codeberg PR only** — procest is on the racing-PR list (external orchestration force-resets `development`); never direct-push
