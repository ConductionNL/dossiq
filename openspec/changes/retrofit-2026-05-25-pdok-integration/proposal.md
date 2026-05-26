# Retrofit — pdok-integration

Describes observed behavior of 2 server-side PDOK ingress services as 2 new REQs on the `pdok-integration` capability. Code already exists — this change retroactively specifies it.

The existing spec covers UI-facing PDOK behavior (tile services, Locatieserver suggest, BAG display in detail views). These REQs document the corresponding backend wrappers: `PdokBagService` (WFS v2_0 lookups, 24h cache, optional routing through OpenConnector) and `PdokLocatieserverService` (suggest / free / lookup / reverse / health).

## Affected code units
- lib/Service/Pdok/PdokBagService.php (3 public lookup methods) — `getNummeraanduiding()`, `getVerblijfsobject()`, `getPand()`
- lib/Service/Pdok/PdokLocatieserverService.php (5 public methods) — `suggest()`, `free()`, `lookup()`, `reverse()`, `health()`

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
