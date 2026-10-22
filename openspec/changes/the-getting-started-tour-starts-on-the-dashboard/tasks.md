# Tasks: the-getting-started-tour-starts-on-the-dashboard

- [x] 1.1 `startOn: ["Dashboard"]` on "dossiq:getting-started"
  - Files: src/manifest.json
- [x] 1.2 Vendored schema 2.94.0, and the lag classifier knows the tour shape
  - Files: tests/schemas/app-manifest-v2.schema.json, tests/validate-manifest.js
  - Test: tests/vitest/validateManifestLag.spec.js (`startOn` is a lag, an unknown tour key fails; the shipped manifest passes with the lag named)
- [x] 1.3 Bump `@conduction/nextcloud-vue` to the release carrying schema 2.94.0 (after ConductionNL/nextcloud-vue#1598 is released). Until then the key does nothing at runtime. Done: package.json requires ^2.126.0.
