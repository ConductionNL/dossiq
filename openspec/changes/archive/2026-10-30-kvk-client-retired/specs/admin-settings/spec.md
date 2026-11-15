# admin-settings (delta)

## MODIFIED Requirements

### Requirement: A card tells the truth about its connection (REQ-ADMIN-019)

A status is a claim the app can back. A card SHALL show one of five states:
Configured, Not configured, Not available, Simulated and Error. A connection
that no code path calls SHALL be seeded Not available, and its message SHALL say
why in words the reader can act on. No card SHALL claim a connection is not
built when the adapter, its DI binding and its tests ship. A card SHALL NOT
offer Open settings for a section that does not exist. A seed SHALL NOT claim
Configured: only a save or a probe may set it.

A connection that IS built and IS called, and that reaches nothing until a tier
key is set, SHALL read Not configured and its message SHALL name the key.

A connection served by a MOCK adapter SHALL read Simulated, and its message
SHALL say so in words a reader understands without opening the code. Simulated
is not a softer Not configured. The seam works: it accepts, it succeeds and it
returns an id, and nothing leaves the instance. Not configured understates
that, and Configured is exactly the claim this page exists to stop the app from
making.

**Feature tier**: MVP

#### Scenario: KvK reads Not available because nothing calls it
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the seeded Integrations page
- **WHEN** the admin reads the KvK card
- **THEN** it SHALL show Not available
- **AND** its message SHALL say dossiq ships no KvK client and that integriq holds the KvK source
- **AND** its message SHALL NOT say the connection is not built
- **AND** it SHALL NOT offer Open settings

#### Scenario: BRP reads Not configured and names the key that wakes it
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the seeded Integrations page
- **AND** `integration.brp.mode` at its `log` default
- **WHEN** the admin reads the BRP card
- **THEN** it SHALL show Not configured
- **AND** its message SHALL name `integration.brp.mode`
- **AND** its message SHALL NOT say the connection is not built
- **AND** it SHALL NOT offer Open settings, because no admin section writes that key

#### Scenario: A fresh instance claims nothing
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** a fresh instance with the seed loaded and no section saved
- **WHEN** the admin opens the Integrations page
- **THEN** no card SHALL read Configured
- **AND** every card that is neither Not available, Simulated nor BRP SHALL read Not configured with the message "Not checked yet"

#### Scenario: A mock adapter reads Simulated, and says the word
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** an instance with no `berichtenbox_adapter` and no `beschikking_template_adapter` configured
- **WHEN** the admin opens the Integrations page
- **THEN** the Berichtenbox and Document templates cards SHALL read Simulated
- **AND** each message SHALL name the mock, on the row itself, without the reader opening a settings page
- **AND** neither SHALL read Configured or Not available

#### Scenario: Naming a real adapter class flips the card
@e2e exclude Substituting an adapter means installing a class this repo does not ship, which no browser flow can do; AdapterHonestyTest asserts the resolution and IntegrationProbesRecordTest the save-to-card seam.

- **GIVEN** an integrator who has installed a real Berichtenbox adapter class
- **WHEN** they name it in the `berichtenbox_adapter` admin setting and save
- **THEN** the Berichtenbox card SHALL read Configured
- **AND** clearing the setting SHALL return the card to Simulated, never to Not configured

### Requirement: Dossiq declares its connections in one static file (REQ-ADMIN-026)

This requirement replaces the seed of REQ-ADMIN-018 and REQ-ADMIN-019. Dossiq
SHALL declare its connections in `lib/Settings/connections.json` with the
twelve keys the page already used: zgw, stuf, kcc, dmn, mailbox, store,
financial, brp, kvk, pdok, berichtenbox and templates (hydra REQ-CONN-001 and
REQ-CONN-008). A `settingsUrl` SHALL only point at a section that exists on
the admin page. KvK SHALL be declared not available with a message saying
dossiq ships no KvK client and integriq holds the KvK source. Berichtenbox and Document
templates SHALL name their adapter config key and a message that says a mock
adapter answers. Their `simulatedValues` SHALL list the empty string and the
mock class the registrar falls back to, so naming the mock class still reads
Simulated (hydra#673, contract D4 rule 3). The connections dossiq probes itself, StUF, the mailbox and
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

#### Scenario: Naming the mock class still reads Simulated
@e2e exclude Rule 3 runs in integriq, and the CI instance cannot set berichtenbox_adapter without occ; tests/Unit/Settings/ConnectionsDeclarationTest.php::testMockBackedSeamsNameTheirMockAsSimulated asserts both lists and that each class implements its seam.

- **GIVEN** `berichtenbox_adapter` set to `OCA\Dossiq\Service\BerichtenboxAdapter\MockAdapter`
- **WHEN** integriq resolves the Berichtenbox row
- **THEN** the row SHALL read Simulated, not Configured

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
- **AND** its message SHALL say dossiq ships no KvK client
- **AND** its message SHALL NOT say the connection is not built
