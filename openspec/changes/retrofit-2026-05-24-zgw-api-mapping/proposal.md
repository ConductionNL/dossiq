# Retrofit — zgw-api-mapping

Describes observed behavior of 13 PHP files (~234 methods) implementing the in-procest ZGW API surface as 5 new REQs. The current `zgw-api-mapping` spec focuses on the OpenRegister-side mapping engine and Endpoint wiring; this retrofit adds REQs for the procest-side controllers, service, middleware, and repair seeder that actually serve the ZGW APIs from the procest app.

## Affected code units
- lib/Controller/ZrcController.php (16 methods) — Zaakregistratiecomponent endpoints
- lib/Controller/ZtcController.php (21 methods) — Zaaktypecatalogus endpoints
- lib/Controller/DrcController.php (32 methods) — Documentregistratiecomponent endpoints
- lib/Controller/BrcController.php (18 methods) — Besluitregistratiecomponent endpoints
- lib/Controller/NrcController.php (10 methods) — Notificatieroutingcomponent endpoints
- lib/Controller/ZgwMappingController.php (6 methods) — Mapping CRUD endpoints
- lib/Service/ZgwService.php (37 methods) — Mapping execution + response shaping
- lib/Service/ZgwMappingService.php (8 methods) — Mapping wiring helpers
- lib/Service/ZgwDocumentService.php (12 methods) — Document operations
- lib/Service/ZgwPaginationHelper.php (1 method) — Pagination helper
- lib/Service/NotificatieService.php (5 methods) — NRC notificatie dispatch
- lib/Middleware/ZgwAuthMiddleware.php (9 methods) — Bearer-token + vertrouwelijkheid auth
- lib/Repair/LoadDefaultZgwMappings.php (36 methods) — Seeds default ZGW mappings on install

## Approach
- File-level survey of each controller's public method surface
- One REQ per logical capability cluster (ZRC, the other four APIs collectively, shared service surface, auth middleware, default-seed repair step)
- Notes flag the two duplicate handlers callout from the coverage report (SendEmailHandler) — out of scope for this cluster, addressed under automatic-actions

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
