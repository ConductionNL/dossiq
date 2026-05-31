# Retrofit — case-location

Describes observed behavior of `LocationService` server-side methods as 2 new REQs on the `case-location` capability.

The existing 4 REQs (REQ-LOC-01..04) are UX-facing: detail map tab, picker, address display, creation. These REQs document the corresponding backend contracts: payload validation (the per-source rule matrix) and the attach/save persistence step that controllers + workflow guards call.

## Affected code units
- lib/Service/LocationService.php (cluster of 4 public methods) — `validate()`, `reverseGeocode()`, `attachToCase()`, `listForCase()`. This retrofit documents `validate()` + `attachToCase()` specifically; `reverseGeocode()` is covered by REQ-LOC-03 already, `listForCase()` is a trivial accessor.

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
