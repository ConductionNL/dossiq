# Retrofit — map-component

Describes observed behavior of 2 PHP files (~6 methods) — the backend GIS proxy controller + service that supports the Leaflet `CaseMap` component — as 2 new REQs.

The existing `map-component` spec is client-focused (Leaflet rendering, marker clustering, RD↔WGS84 conversion). These REQs document the server-side companion: how the Vue component reaches external WMS/WFS/PDOK services via a CORS-bypassing backend proxy with allowlist + rate limiting + response caching.

## Affected code units
- lib/Controller/GisProxyController.php (2 methods) — `proxy()` and `capabilities()` action endpoints
- lib/Service/GisProxyService.php (4 methods) — `proxyRequest()`, `getCapabilities()`, allowlist validation, per-user rate limiting

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
