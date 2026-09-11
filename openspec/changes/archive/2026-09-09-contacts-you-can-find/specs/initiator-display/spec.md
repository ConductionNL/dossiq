## ADDED Requirements

### Requirement: The Contacts index lists the people dossiq knows (REQ-ID-4)

You find a person by name or number. The manifest page `Contacts`
(route `/contacts`, type `index`, register `dossiq`, schema `brpPerson`)
SHALL list `displayName`, `citizenServiceNumber`, the residence city and
`description`, with the index search box covering name and number. The view
action of a row SHALL open `ContactDetail`.

The page SHALL NOT carry a `folderSidebar`. `contacts-domain` asked for one
with a People folder and a hidden Organisations folder; measured against
`@conduction/nextcloud-vue` 2.41.0, `CnIndexPage.folderSidebarFolders()`
returns `folders[]` verbatim so there is no hidden state, and
`filterField: "@self.schema"` is not a filter OpenRegister answers, so the
only folder that could ship would have emptied the list on its first click.
Organisations are reached through REQ-ID-6 instead, and the manifest SHALL
carry the measurement as a note on the page.

#### Scenario: Find a person by name
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded `brpPerson` row with display name Jansen
- **WHEN** you open Contacts and type Jansen in the search box
- **THEN** the list SHALL show that row with its citizen service number

#### Scenario: No folder pane, and the way to organisations beside it
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** the Contacts index
- **WHEN** you read the page
- **THEN** it SHALL render no folder pane
- **AND** the navigation SHALL offer the Organisations index without expanding anything

### Requirement: The Organisations index lists the organisations dossiq knows (REQ-ID-6)

You find an organisation without already holding a case that names it. The
manifest page `Organisations` (route `/organisations`, type `index`, register
`dossiq`, schema `kvkCompany`) SHALL list `tradeName`, `kvkNumber`,
`legalForm`, the address place and `description`, with the index search box
covering name and number. The view action of a row SHALL open
`OrganisationDetail`. A column whose key is a dot-path SHALL be
`sortable: false`, because such a key resolves client-side for rendering but
is not a column OpenRegister can order by.

The page SHALL be reached by a menu entry `OrganisationsMenu` that
`src/menu-layout.json` relocates under `Contacts`, so the top-level count does
not move: ADR-097 Decision 1 counts top-level entries only. `Contacts` SHALL
carry `open: true`, because `CnAppNav` auto-expands a group only when one of
its children is the active route, so on `/contacts` the child would otherwise
be hidden behind a chevron.

#### Scenario: The organisations index is a child of Contacts
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** the Contacts index
- **WHEN** you read the navigation
- **THEN** an Organisations entry SHALL be visible under Contacts
- **AND** the top-level entries SHALL still be Dashboard, My work, Contacts and Objects

#### Scenario: An organisation opens on its own page
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded `kvkCompany` row
- **WHEN** you open the Organisations index and follow its row
- **THEN** you SHALL land on `/organisations/:id` for that row
- **AND** the card SHALL show the trade name and the KvK number
