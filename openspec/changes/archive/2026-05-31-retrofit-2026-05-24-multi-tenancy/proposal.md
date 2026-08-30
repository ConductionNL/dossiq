# Retrofit — multi-tenancy

Describes observed behavior of 2 PHP files (~13 methods) — tenant controller (provision/usage/current) and the OR-Organisation-backed tenant service — as 5 new REQs. Generic tenant CRUD is delegated to OpenRegister manifest-rendered endpoints per ADR-022; procest only owns the domain logic captured here.

## Affected code units

- lib/Controller/TenantController.php (5 methods) — provision, usage, current, platform-admin guard
- lib/Service/TenantService.php (8 methods) — OR-Organisation backed tenant resolution, provisioning via `TenantLifecycleService`, resource-usage aggregation, status read

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
