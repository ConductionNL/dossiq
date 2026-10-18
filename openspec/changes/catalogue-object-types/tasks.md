# Tasks: catalogue-object-types

- [x] 1.1 List the schemas of dossiq's register on the server
  - Files: lib/Service/Catalogue/ObjectTypeCatalogue.php
  - Test: tests/Unit/Service/Catalogue/ObjectTypeCatalogueTest.php
- [x] 1.2 Admin-only endpoint `GET /api/catalogue/object-types`
  - Files: lib/Controller/CatalogueController.php, appinfo/routes.php
  - Test: tests/Unit/Controller/CatalogueControllerTest.php
- [x] 1.3 The Objecttypen card reads the endpoint through `object-table`
  - Files: src/manifest.d/64-catalogue.json
  - Test: tests/vitest/cataloguePage.spec.js
- [ ] 1.4 A "used in N case types" column, once case types reference object types
