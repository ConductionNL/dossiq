## ADDED Requirements

### Requirement: The Integrations page lists dossiq's rows from integriq's registry (REQ-ADMIN-025)

This requirement replaces the data source of REQ-ADMIN-018. The page, its
route, its columns and its admin gate stay as REQ-ADMIN-018 describes them.
The rows are no longer `dossiqIntegration` objects: the page SHALL be an
`index` page over integriq's `app_connection` schema, preset to `app` equal to
`dossiq` through its menu entry's `query`, and SHALL declare Integriq as the
app it requires (hydra REQ-CONN-006). The menu entry SHALL only render when
integriq is installed. The page SHALL NOT offer the generic Add button. Its
Add integration action SHALL open integriq's Connections overview with
`app=dossiq&link=1`, where a source is linked to a declared connection.

**Feature tier**: MVP

#### Scenario: The menu opens the page on dossiq's own rows
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** dossiq and integriq are installed and integriq has synced dossiq's declaration
- **WHEN** an admin opens the gear and chooses Integrations
- **THEN** the page SHALL list the twelve declared connections in declared order
- **AND** every listed row SHALL have `app` equal to `dossiq`

#### Scenario: Without integriq the page says what is missing
@e2e exclude The CI instance installs integriq, so no browser flow can reach a dossiq without it; the manifest declaration is asserted in tests/vitest/integrationsPage.spec.js and the screen is nextcloud-vue's CnPageRenderer.

- **GIVEN** integriq is not installed
- **WHEN** an admin opens `/settings/integrations` by URL
- **THEN** the missing-dependency screen SHALL name Integriq
- **AND** the menu SHALL NOT list Integrations

#### Scenario: Add integration goes to integriq, not to a form
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the Integrations page
- **WHEN** the admin looks for a way to add a connection
- **THEN** no generic Add button SHALL be offered
- **AND** the Add integration action SHALL open integriq's Connections overview with `app=dossiq` and `link=1`

### Requirement: Dossiq declares its connections in one static file (REQ-ADMIN-026)

This requirement replaces the seed of REQ-ADMIN-018 and REQ-ADMIN-019. Dossiq
SHALL declare its connections in `lib/Settings/connections.json` with the
twelve keys the page already used: zgw, stuf, kcc, dmn, mailbox, store,
financial, brp, kvk, pdok, berichtenbox and templates (hydra REQ-CONN-001 and
REQ-CONN-008). A `settingsUrl` SHALL only point at a section that exists on
the admin page. KvK SHALL be declared not available with a message saying the
adapter is built and bound and nothing calls it. Berichtenbox and Document
templates SHALL name their adapter config key and a message that says a mock
adapter answers. The connections dossiq probes itself, StUF, the mailbox and
the store, SHALL declare no config keys.

BRP SHALL be declared with an `unconfiguredMessage` that names
`integration.brp.mode`, so REQ-ADMIN-019's BRP promise holds on the registry.

**Feature tier**: MVP

#### Scenario: The declaration keeps the page's keys and links
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the declaration integriq synced
- **WHEN** the admin reads the Integrations page
- **THEN** the twelve rows SHALL carry the titles and order the seed carried
- **AND** the StUF row's settings link SHALL open `/settings/admin/dossiq#section-stuf`
- **AND** BRP, KvK, PDOK, Berichtenbox and Document templates SHALL offer no settings link

#### Scenario: A mock adapter still reads Simulated
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** no `berichtenbox_adapter` and no `beschikking_template_adapter` configured
- **WHEN** the admin opens the Integrations page
- **THEN** the Berichtenbox and Document templates rows SHALL read Simulated
- **AND** each message SHALL say a mock adapter answers

#### Scenario: BRP reads Not configured and names the key that wakes it
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the declaration integriq synced
- **AND** `integration.brp.mode` at its `log` default
- **WHEN** the admin reads the BRP row
- **THEN** it SHALL read Not configured
- **AND** its message SHALL name `integration.brp.mode`
- **AND** it SHALL NOT offer Open settings

#### Scenario: KvK reads Not available and says why
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the declaration integriq synced
- **WHEN** the admin reads the KvK row
- **THEN** it SHALL read Not available
- **AND** its message SHALL say the adapter is built and bound
- **AND** its message SHALL NOT say the connection is not built

### Requirement: A probe reports and a save asks integriq to look again (REQ-ADMIN-027)

This requirement replaces the write path of REQ-ADMIN-020. Dossiq SHALL NOT
write `status`, `statusMessage` or `checkedAt` on any row. When the StUF
endpoint list, the mailbox Test connection or the store save learns a
connection's state, dossiq SHALL send `ConnectionStatusReportedEvent` with
app `dossiq`, the connection key, the status and the message. When a settings
save carries a config key a connection declares, dossiq SHALL send
`ConnectionRefreshRequestedEvent` with app `dossiq` and that key, and integriq
SHALL decide the status (hydra REQ-CONN-004). Both events SHALL be named by
string and sent only when the class exists. Neither SHALL change the response
of the request that sent it, whether integriq is absent or its listener fails.

**Feature tier**: MVP

#### Scenario: A failed mailbox test reaches the row
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the mailbox section saved with an IMAP host that does not answer
- **WHEN** the admin chooses Test connection
- **THEN** dossiq SHALL send a report for `mailbox` with status `error` and the failure text
- **AND** the Mailbox row SHALL read Error on the next page load

#### Scenario: Saving the KCC section asks for a refresh
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the KCC row reading Not configured
- **WHEN** the admin fills the KCC section's required field and saves
- **THEN** dossiq SHALL send a refresh request for `kcc` and no status of its own
- **AND** the KCC row SHALL read Configured on the next page load

#### Scenario: Without integriq nothing is sent and nothing breaks
@e2e exclude The CI instance installs integriq; tests/Unit/Service/IntegrationStatusServiceTest.php asserts that nothing is dispatched or logged when the event class is absent.

- **GIVEN** integriq is not installed
- **WHEN** the admin runs the mailbox Test connection
- **THEN** no event SHALL be sent and no warning SHALL be logged
- **AND** the test's own response SHALL be unchanged

#### Scenario: A failing listener never reaches the probe
@e2e exclude A listener that throws cannot be installed from a browser; tests/Unit/Service/IntegrationStatusServiceTest.php asserts the exception is caught and logged.

- **GIVEN** integriq's report listener throws
- **WHEN** the StUF endpoint list loads
- **THEN** the endpoint list SHALL answer as it would without the report
- **AND** the failure SHALL be logged as a warning naming the connection

### Requirement: The old integration schema stays one release and is no longer fed (REQ-ADMIN-028)

Dossiq SHALL stop seeding `dossiqIntegration` rows and SHALL keep the schema in
its register for one release, with a note naming this change. Its removal SHALL
be a tracked follow-up (hydra REQ-CONN-008, contract D10 step 4).

**Feature tier**: MVP

#### Scenario: A fresh install seeds no dossiqIntegration rows
@e2e exclude Asserted on the shipped files: tests/vitest/integrationsPage.spec.js checks that no register.d fragment seeds the schema and that the schema carries the note.

- **GIVEN** a fresh install of this version
- **WHEN** the register import runs
- **THEN** the `dossiqIntegration` schema SHALL exist
- **AND** it SHALL hold no rows
