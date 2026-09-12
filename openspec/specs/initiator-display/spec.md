# initiator-display Specification

**Status:** done
**OpenSpec changes**: [brp-kvk-register-sets](../../changes/archive/2026-07-06-brp-kvk-register-sets/) _(archived 2026-07-06)_
**Scope:** Initiator details on the case detail view
**Depends on:** `initiator-selection` (this change — the stored initiator reference)
**Standards:** GEMMA Zaakafhandel (initiator visible on the zaak), ZGW ZRC Rol betrokkene
**Feature tier:** MVP

## Purpose

How the case shows who filed it. The stored initiator reference is rendered on
the case detail view, so a handler can see the person or company behind the case
without leaving the page.

## Requirements

### Requirement: Case detail shows the initiator

You see who asked for the case at the top of the case page. The `initiator`
widget on `CaseDetail` used to render a person card in the first row of the
layout, beside `case-core`, when the projection fields are set. The card
SHALL show `initiatorDisplayName`, the type (Person, Company or Contact), the
identifying `initiatorSourceId` as a link to the source record (the
`brpPerson` or `kvkCompany` register object, or the contact), and the address
resolved from the source row. When `requester` is set and the projection is
empty, the card SHALL resolve the row by uuid and fill the projection. Cases
without a requester SHALL show no empty card.

#### Scenario: Initiator visible on the case
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a case with `initiatorType: person` and a seeded persona as requester
- **WHEN** a handler opens the case page
- **THEN** the first row SHALL hold a card with the persona's name, the type Person, the BSN and the address
- **AND** the BSN SHALL link to the seeded `brpPerson` record
@e2e exclude The initiator card was removed from the case page on 2026-09-12 (Ruben, Buildiq edit mode): the requester reads in the Data tab's Requester field, and the card that carried this scenario no longer renders. The scenario stays as the record of what the card did; nothing on the case page answers to it now.


#### Scenario: A company card links to the KvK record
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a case with `initiatorType: company` and `initiatorSourceId: 69599084`
- **WHEN** a handler opens the case page
- **THEN** the card SHALL show the company's display name, the type Company and the KvK number
- **AND** the KvK number SHALL link to the seeded `kvkCompany` record
@e2e exclude The initiator card was removed from the case page on 2026-09-12 (Ruben, Buildiq edit mode): the requester reads in the Data tab's Requester field, and the card that carried this scenario no longer renders. The scenario stays as the record of what the card did; nothing on the case page answers to it now.


#### Scenario: No initiator, no clutter
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a case without a requester
- **WHEN** a handler opens the case page
- **THEN** no initiator card SHALL render
@e2e exclude The initiator card was removed from the case page on 2026-09-12 (Ruben, Buildiq edit mode): the requester reads in the Data tab's Requester field, and the card that carried this scenario no longer renders. The scenario stays as the record of what the card did; nothing on the case page answers to it now.


### Requirement: The case list names the requester (REQ-ID-2)

You see who asked for each case in the list and filter on their name. Page
`Cases` SHALL render a Requester column over `initiatorDisplayName` after
`title`, and its sidebar SHALL offer a text filter on the same field. The
column reads the projection until `CnIndexPage` renders a label field for a
`$ref` column; the data does not change when that lands.

#### Scenario: The requester is a column
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a seeded case whose requester is a seeded persona
- **WHEN** a handler opens Cases in table view
- **THEN** the row SHALL show the persona's name in the Requester column

#### Scenario: The list filters on the requester's name
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** two seeded cases with different requesters
- **WHEN** the handler types the first requester's surname into the Requester filter
- **THEN** the list SHALL show the first case and not the second

### Requirement: A protected person's number stays masked until you reveal it (REQ-ID-3)

You see when a person's data is protected, and the BSN stays hidden until you
ask for it. When the source `brpPerson` row carries `indicatieGeheim: true`,
the card SHALL show a Protected marker and render the BSN as five dots plus
the last four digits. A Reveal button SHALL fetch the `brpPerson` row through
OpenRegister with `_reason: "bsn-reveal"` and then show the full number.
OpenRegister SHALL log that read, attributed to the case's processing
activity; dossiq SHALL write no log row itself. A person without the flag
SHALL render the BSN in full.

#### Scenario: The BSN is masked
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a case whose requester is the seeded protected persona
- **WHEN** a handler opens the case page
- **THEN** the card SHALL show the Protected marker
- **AND** the BSN SHALL read as five dots followed by its last four digits
@e2e exclude The initiator card was removed from the case page on 2026-09-12 (Ruben, Buildiq edit mode): the requester reads in the Data tab's Requester field, and the card that carried this scenario no longer renders. The scenario stays as the record of what the card did; nothing on the case page answers to it now.


#### Scenario: A reveal shows the number and is a logged read
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** the same case
- **WHEN** the handler presses Reveal
- **THEN** the card SHALL show the full BSN
- **AND** the page SHALL make one read of the `brpPerson` row carrying `_reason=bsn-reveal`
@e2e exclude The initiator card was removed from the case page on 2026-09-12 (Ruben, Buildiq edit mode): the requester reads in the Data tab's Requester field, and the card that carried this scenario no longer renders. The scenario stays as the record of what the card did; nothing on the case page answers to it now.


#### Scenario: An unprotected person is not masked
@e2e tests/e2e/case-requester.spec.ts

- **GIVEN** a case whose requester is a seeded persona without `indicatieGeheim`
- **WHEN** a handler opens the case page
- **THEN** the card SHALL show the full BSN and no Protected marker
@e2e exclude The initiator card was removed from the case page on 2026-09-12 (Ruben, Buildiq edit mode): the requester reads in the Data tab's Requester field, and the card that carried this scenario no longer renders. The scenario stays as the record of what the card did; nothing on the case page answers to it now.


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
