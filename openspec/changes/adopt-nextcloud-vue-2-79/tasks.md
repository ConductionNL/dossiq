# Tasks: adopt-nextcloud-vue-2-79

- [x] 1.1 Bump `@conduction/nextcloud-vue` to ^2.79.0 with
  `npm install @conduction/nextcloud-vue@2.79.0`; refresh the vendored
  manifest schema.
- [x] 2.1 Drop `headerFilters: false` from the Cases page in
  `src/menu-layout.simple.json` (D-1).
- [x] 2.2 `tests/vitest/simpleListAndDashboard.spec.js` asserts the funnels
  are on: no page or column opt-out, and the library default is on.
- [x] 3.1 Declare `hideOnCreate` on the `propertyDefinition` schema, version
  1.6.0, digests refreshed (D-2, D-3).
- [x] 3.2 `hideOnCreate: true` on the Woo `receiptDate` definition in
  `register.d/81-woo-verzoek.json` and `templates/woo-verzoek.json`.
- [x] 3.3 `tests/vitest/wooReceiptDateOffCreateForm.spec.js` fails if the
  flag or its schema declaration disappears, and runs the library's own
  `propertiesFromDefinitions` through dossiq's map.
- [x] 3.4 Name `hideOnCreate` in the case form map and in `RENDERER_ROLES`;
  `DeclaredDisplayFlagHasReaderTest` names the new display flag (D-2).
