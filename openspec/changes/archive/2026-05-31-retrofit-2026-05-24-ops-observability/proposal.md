# Retrofit — ops-observability

Describes observed behavior of 1 PHP file (~6 methods) — procest's health-check controller for container orchestration and monitoring — as 3 new REQs.

## Affected code units

- lib/Controller/HealthController.php (6 methods) — `index` aggregate health-check JSON endpoint with DB / OpenRegister / filesystem sub-checks and app-version reporting

## Note on observed vs reported

The coverage report mentions `/health/db` / `/health/<sub>` paths; the implementation only exposes a single `index()` endpoint that aggregates all three sub-checks. The spec captures the implemented behavior; per-component endpoints remain a future-work item.

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
