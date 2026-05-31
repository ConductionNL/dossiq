# Retrofit — wms-wfs-layers

Describes observed behavior of 2 PHP files (~8 methods) — WMS/WFS proxy controller + layer resolution service — as 2 new REQs.

## Affected code units
- lib/Controller/WmsWfsController.php (1 method) — per-layer WMS/WFS proxy action endpoint
- lib/Service/WmsWfsService.php (7 methods) — layer resolution per case type, layer validation, GetMap/GetFeature URL construction, allowlisted proxy delegation

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
