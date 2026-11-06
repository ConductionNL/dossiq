# Tasks: ui-facing-names-are-generic

- [x] 1 Generic routes with alias routes for every old URL, each alias a `<method>LegacyPath()` delegate with the same
      auth posture (`tests/Unit/AppInfo/RenamedRouteAliasesTest.php`, `tests/e2e/renamed-api-aliases.spec.ts`).
- [x] 2 Generic report keys beside the old ones (`DeadlineReportingServiceTest`).
- [x] 3 Series under both keys (`ProcessingTimeSeriesPublisherTest`).
- [x] 4 Widget, page and menu ids renamed; `RewriteRenamedLayoutIds` rewrites stored arrangements
      (`RenamedLayoutIdsTest`, `RewriteRenamedLayoutIdsTest`).
- [x] 5 Report settings on generic keys with a fallback read and `CarryRenamedSettingKeys`
      (`WooReportSwitchesTest`, `CarryRenamedSettingKeysTest`, `SettingsServiceTest`).
- [x] 6 Frontend callers, manifest URLs and the settings components renamed; `/woo-reports` redirects.
- [ ] 7 One release later: drop the old report keys, the `procest-sla-*` series and the deprecated alias routes (not the
      payment callback's).
