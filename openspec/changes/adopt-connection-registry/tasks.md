# Tasks: adopt-connection-registry

- [x] 1.1 `lib/Settings/connections.json`: the twelve keys, titles,
  descriptions, order and settings links of the seed; `requiredConfig` from
  `SAVE_REQUIRED_KEYS`; `adapter` blocks for berichtenbox and templates; KvK
  `available: false`; `sourceTemplate` for BRP and KvK.
  - `tests/Unit/Settings/ConnectionsDeclarationTest.php`: parses, names this
    app, twelve keys equal to `KEYS`, only D2 fields, every link lands on a
    section in `AdminRoot.vue`, the save map equals the declared config keys.
- [x] 2.1 `lib/Service/IntegrationStatusService.php`: `record()` sends
  `ConnectionStatusReportedEvent`, `recordFromSave()` sends
  `ConnectionRefreshRequestedEvent`, both behind `class_exists`, never throws.
  - `tests/Unit/Service/IntegrationStatusServiceTest.php`: event and arguments
    when the class exists, nothing sent or logged when absent, unknown key
    refused, a throwing listener never escapes.
  - Stubs `tests/Stubs/Integriq/Event/ConnectionStatusReportedEvent.php` and
    `ConnectionRefreshRequestedEvent.php`, loaded by `tests/bootstrap.php`
    and listed in `psalm.xml`.
  - `tests/Unit/AppInfo/AdapterHonestyTest.php` reads the mock messages from
    the declaration.
- [x] 3.1 `src/manifest.json` `#Integrations`: `integriq/connection`,
  `requiresApp`, `showAdd: false`, header action Add integration; menu entry
  `query` and `visibleIf.appInstalled`.
- [x] 3.2 `src/services/formatters.js`: `connectionStatus` and
  `connectionSettingsLabel`, old names kept as aliases.
- [x] 3.3 `src/customComponents.js`: `openIntegriqConnections`.
  - `tests/vitest/integrationsPage.spec.js` and `tests/vitest/formatters.spec.js`.
- [x] 4.1 Remove `lib/Settings/register.d/96-integrations.json`; `_meta` note
  on `dossiqIntegration` in `lib/Settings/dossiq_register.json`.
- [x] 5.1 `tests/e2e/integrations-page.spec.ts` reads `integriq/connection`
  filtered on `app=dossiq`. Not run here: it needs integriq's side installed.
- [ ] 6.1 After integriq ships: run the e2e spec against an instance with both
  apps, then archive this change and fold the delta into `admin-settings`.
- [ ] 6.2 Follow-up issue: remove `dossiqIntegration` from the register.
