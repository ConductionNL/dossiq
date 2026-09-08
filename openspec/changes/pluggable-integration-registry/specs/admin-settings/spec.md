## ADDED Requirements

### Requirement: An Integrations page under the gear (REQ-ADMIN-018)

You see every external connection on one page. The app SHALL offer a page
Integrations of type `settings` under the gear foldout, admin only, that
lists one card per `dossiqIntegration` object in `order`, with the
connection's title, its status, its status message and when it was last
checked. The page SHALL carry no configuration fields of its own: each card
SHALL offer an Open settings action that opens the section of the Nextcloud
admin page that configures the connection. A user who is not an admin SHALL
NOT see the menu entry and SHALL NOT reach the route.

**Feature tier**: MVP

#### Scenario: The page lists the ten connections
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** an admin on a fresh instance with the seed loaded
- **WHEN** they open the gear and choose Integrations
- **THEN** the page SHALL show ten cards in the seeded order, ZGW first and PDOK last
- **AND** each card SHALL show a title and a status

#### Scenario: Open settings lands on the section
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the Integrations page
- **WHEN** the admin chooses Open settings on the StUF card
- **THEN** the browser SHALL be at `/settings/admin/dossiq#section-stuf`
- **AND** the StUF-ZKN Endpoints section SHALL be in view

#### Scenario: A regular user does not reach the page
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** a user who is not an admin
- **WHEN** they open the gear
- **THEN** Integrations SHALL NOT be listed
- **AND** opening `/settings/integrations` directly SHALL NOT render the cards

### Requirement: A card tells the truth about its connection (REQ-ADMIN-019)

A status is a claim the app can back. A card SHALL show one of four states:
Configured, Not configured, Not available and Error. A connection whose
specification has no implementation SHALL be seeded Not available with the
message "Specified, not built yet" and SHALL NOT offer Open settings for a
section that does not exist. A seed SHALL NOT claim Configured: only a save
or a probe may set it.

**Feature tier**: MVP

#### Scenario: BRP and KvK read Not available
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the seeded Integrations page
- **WHEN** the admin reads the BRP and KvK cards
- **THEN** both SHALL show Not available with the message "Specified, not built yet"
- **AND** neither SHALL offer Open settings

#### Scenario: A fresh instance claims nothing
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** a fresh instance with the seed loaded and no section saved
- **WHEN** the admin opens the Integrations page
- **THEN** no card SHALL read Configured
- **AND** every card that is not Not available SHALL read Not configured with the message "Not checked yet"

### Requirement: A probe or a save updates the card (REQ-ADMIN-020)

What the admin did last is what the card shows. When the StUF health check
or the mailbox Test connection runs, the matching `dossiqIntegration`
object SHALL be updated with the outcome as `status` (Configured on
success, Error on failure), the outcome text as `statusMessage` and the
time as `checkedAt`. When a section without a probe is saved, its object
SHALL become Configured when the section's required fields are filled and
Not configured when they are cleared. The page SHALL show the new state on
its next load.

**Feature tier**: MVP

#### Scenario: A failed mailbox test shows Error
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the mailbox section saved with an IMAP host that does not answer
- **WHEN** the admin chooses Test connection and then opens the Integrations page
- **THEN** the Mailbox card SHALL read Error
- **AND** its message SHALL name the failure the test reported
- **AND** its checked-at SHALL be within the last minute

#### Scenario: Saving the KCC section marks it Configured
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** the KCC card reading Not configured
- **WHEN** the admin fills the KCC section's required fields, saves, and opens the Integrations page
- **THEN** the KCC card SHALL read Configured

#### Scenario: A StUF endpoint's health reaches the card
@e2e exclude The StUF health check needs a SOAP endpoint that answers; CI has none, so the write is covered by a unit test on IntegrationStatusService and the controller.

- **GIVEN** a StUF endpoint whose health check answers healthy
- **WHEN** the endpoint list is loaded
- **THEN** the StUF card SHALL read Configured with the endpoint's name in its message

### Requirement: The page shows which apps the connections need (REQ-ADMIN-021)

A connection that needs another app should say so where the admin looks.
The Integrations page SHALL show a Required apps section that lists the
app's declared dependencies with whether each is installed, rendered by the
shared dependency component, so a missing app is visible beside the
connections that need it.

**Feature tier**: MVP

#### Scenario: A missing dependency is listed
@e2e tests/e2e/integrations-page.spec.ts

- **GIVEN** an instance where one declared dependency is not installed
- **WHEN** the admin opens the Integrations page
- **THEN** the Required apps section SHALL list that app as not installed
- **AND** SHALL NOT list the apps that are installed
