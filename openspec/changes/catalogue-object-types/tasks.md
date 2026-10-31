# Tasks: catalogue-object-types

- [x] 1.1 Count the cases per linked object type on the server
  - Files: lib/Service/Catalogue/ObjectTypeCatalogue.php
  - Test: tests/Unit/Service/Catalogue/ObjectTypeCatalogueTest.php
- [x] 1.2 Admin-only endpoint `GET /api/catalogue/object-types`, 503 on a failed read
  - Files: lib/Controller/CatalogueController.php, appinfo/routes.php
  - Test: tests/Unit/Controller/CatalogueControllerTest.php
- [x] 1.3 The Objecttypen card reads the endpoint through `object-table`, with a Gebruikt in column
  - Files: src/manifest.d/64-catalogue.json, src/services/formatters.js (caseCount)
  - Test: tests/vitest/cataloguePage.spec.js, tests/vitest/formatters.spec.js
- [ ] 1.4 A "used in N case types" column. Blocked: a case type holds no reference to an object type, so the link is missing
