# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md`. This
file only records how dossiq meets it and where it could not.

## D1. The declaration mirrors the seed

`lib/Settings/connections.json` carries the twelve keys of
`96-integrations.json` in the same order: zgw, stuf, kcc, dmn, mailbox, store,
financial, brp, kvk, pdok, berichtenbox and templates. Titles, descriptions,
`order` and `settingsUrl` are copied. An empty `settingsUrl` in the seed is an
omitted field here, as contract D2 asks.

- `requiredConfig` comes from `IntegrationStatusService::SAVE_REQUIRED_KEYS`.
- StUF, the mailbox and the store carry no config keys. Dossiq probes them and
  reports what it found.
- Berichtenbox and Document templates carry an `adapter` block naming
  `berichtenbox_adapter` and `beschikking_template_adapter`, with the mock
  messages the service used to write. They also keep that key in
  `requiredConfig`, so a filled key reads Configured under contract D4 rule 5
  instead of falling through to Not checked yet.
- Both adapter blocks list `simulatedValues`: the empty string and the mock
  class the registrar falls back to. An admin who names the mock class in the
  key binds the mock too, and without it in the list that row would read
  Configured under contract D4 rule 5 while the mock answered.
- BRP carries `unconfiguredMessage` with the seed's message, which names
  `integration.brp.mode`, the key that wakes it. No admin section writes that
  key, so the row is the only place an integrator learns it.
- KvK is `available: false`. The seed's reason holds: the adapter is built and
  bound, and no screen or service calls it.
- `sourceTemplate` is set only where integriq ships a template today:
  `brp-haalcentraal` for BRP and `kvk` for KvK. Berichtenbox has none yet, so
  the field is omitted rather than pointing at nothing.

`ConnectionsDeclarationTest` keeps the file, `KEYS`, `SAVE_REQUIRED_KEYS`
and the anchors in `AdminRoot.vue` in step.

## D2. The page

- `register: integriq`, `schema: app_connection`, `requiresApp: {id: integriq,
  name: Integriq}`. The installed nextcloud-vue 2.53.1 renders the
  missing-dependency screen for a page whose `requiresApp` is absent.
- The menu entry carries `query: {app: dossiq}` and
  `visibleIf.appInstalled: integriq`. CnAppNav turns `query` into the route
  query and the index page merges it into the fetch as a bare filter key, the
  spelling the objects endpoint reads.
- The columns stay: title, status, status message, last checked, settings.
- `showAdd: false`. A row nothing declared has nothing to check (contract D9).
- A header action Add integration calls the `openIntegriqConnections` handler
  in `src/customComponents.js`. A header action's `navigate` handler only
  pushes a route name inside dossiq, so leaving the app needs a function.
  It opens `/apps/integriq/connections?app=dossiq&link=1`.

The route is the one contract D9 names (hydra#667).

## D3. The writer

`IntegrationStatusService` keeps its method names, so the four callers do not
change: `SettingsController::update`, `StufController::endpoints`,
`EmailTemplateService::recordMailboxStatus` and
`ConfiguredRegistryService::recordStoreStatus`.

- `record($key, $status, $message)` still refuses an unknown key or status,
  then sends `ConnectionStatusReportedEvent('dossiq', $key, $status, $message)`.
- `recordFromSave($saved)` sends `ConnectionRefreshRequestedEvent('dossiq',
  $key)` for each connection whose config keys the payload named. It sends no
  status. Integriq reads the saved values itself.
- Both classes are named by string constant and resolved with `class_exists`,
  as `PublicationService` does for `DeliveryRequestedEvent` (ADR-041).
- Without integriq nothing is sent and nothing is logged. A missing optional
  app is not a fault, and a warning on every IMAP test would fill the log.
- A listener that throws is caught and logged as a warning. It never reaches
  the probe that reported.

The service no longer needs `SettingsService` or `IAppConfig`, so both leave
its constructor.

## D4. The seed goes, the schema stays one release

`96-integrations.json` is deleted rather than emptied.

- Import upserts by slug and deletes nothing, so removing the file removes no
  row on an upgraded instance.
- An emptied file would keep a register fragment whose only content is a note,
  and a later reader would have to work out that it seeds nothing on purpose.
- The rollback still works: a revert restores the file with the rest of the
  change.

`dossiqIntegration` stays in `dossiq_register.json` with a `_meta` note naming
this change. Its removal is a follow-up issue listed in the PR body.

## D5. Formatters

`connectionStatus` and `connectionSettingsLabel` are the names contract D8
uses, so every adopting app can copy the same seven lines. `integrationStatus`
and `integrationSettingsLabel` stay as aliases of the same functions until
nothing in the fleet names them.

`connectionStatus` names six statuses, `limited` among them ("Limited",
"Beperkt"). The copy stays local: nextcloud-vue#1163 made both formatters
built-ins, and the pinned 2.53.1 was released before it merged.

## D6. The contract amendments (hydra#673)

The contract gained `adapter.jsonPath`, `adapter.simulatedValues`,
`reportedOnly`, rule 4a, the `limited` status and an hourly resolve of every
row. Dossiq takes two of them.

- **`simulatedValues`** on Berichtenbox and Document templates, as D1 says.
- **`limited`** in the formatter and in `IntegrationStatusService::STATUSES`.
  No dossiq caller reports it yet.

Dossiq leaves the rest alone, for these reasons.

- **No `reportedOnly`.** The flag skips rules 3 and 5. Every row with an
  adapter key or required settings is one integriq can judge from app config.
  StUF, the mailbox and the store carry neither, so the flag would change
  nothing on them.
- **No `jsonPath`.** Both adapter keys hold a class name, not a JSON object.
- **BRP keeps `unconfiguredMessage`.** `integration.brp.mode` could be
  declared as an adapter key with `simulatedValues` `["", "log"]`. The log
  adapter does not answer like a mock, though: it returns a deferred lookup and
  no person. Not configured is the true reading, and REQ-ADMIN-019 promises
  it. The hourly resolve now picks up a mode set with `occ`.

## Risks

- **ZGW may read Configured on a fresh instance.** Its `requiredConfig` is
  `register` and `case_schema`, which the register import fills. The seed said
  Not checked yet until someone saved the section. Contract D4 rule 5 now says
  "Required settings are filled.", which stays true after an import.
- **Upgraded instances keep old rows.** Nothing reads them after this change.
  The follow-up that removes the schema removes them.
- **Named arguments on events dossiq cannot see.** The events are built with
  the parameter names from contract D6. The test stubs mirror them verbatim; if
  integriq ships other names, `send()` catches the error, logs a warning and
  the report is lost rather than the probe.
