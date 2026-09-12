## MODIFIED Requirements

### Requirement: The Contacts index lists the people dossiq knows (REQ-ID-4)

You find a person by name or number. The manifest page `Contacts`
(route `/contacts`, type `index`, register `dossiq`, schema `brpPerson`)
SHALL list `displayName`, `citizenServiceNumber`, the residence city and
`description`, with the index search box covering name and number. The view
action of a row SHALL open `ContactDetail`. The page SHALL offer saved views
and the object sidebar.

The page SHALL NOT carry a `folderSidebar`. `contacts-domain` asked for one
with a People folder and a hidden Organisations folder; measured against
`@conduction/nextcloud-vue` 2.41.0 and re-measured against 2.42.0,
`CnIndexPage.folderSidebarFolders()` returns `folders[]` verbatim so there is
no hidden state, and `filterField: "@self.schema"` is not a filter
OpenRegister answers, so the only folder that could ship would have emptied
the list on its first click. Organisations are reached through REQ-ID-6
instead, and the manifest SHALL carry the measurement as a note on the page.

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

## ADDED Requirements

### Requirement: A contact page shows the person or organisation with their cases (REQ-ID-5)

You see who someone is and what they have with you. The manifest pages
`ContactDetail` (route `/contacts/:id`, schema `brpPerson`) and
`OrganisationDetail` (route `/organisations/:id`, schema `kvkCompany`) SHALL
each render a read-only data card `contact-card`, an `object-list`
`contact-cases` over `case` where `requester = @objectId` with the columns
identifier, title, status and deadline, an `object-list` `contact-moments`
over `contactmoment` where `contact = @objectId`, and the audit sidebar. A
case row SHALL open `CaseDetail`. The initiator card on `CaseDetail` SHALL
link the identifying number to `ContactDetail` for a person and to
`OrganisationDetail` for a company, in place of the raw register row. A
protected person's number SHALL stay masked on the card as REQ-ID-3 requires.

#### Scenario: A person's cases on their page
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded person and a case whose `requester` is that person
- **WHEN** you open the person from the Contacts index
- **THEN** the card SHALL show their name and number
- **AND** the cases list SHALL show the case with identifier, title, status and deadline
- **AND** the case row SHALL open the case page

#### Scenario: A person without cases says so
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded person with no case naming them
- **WHEN** you open their page
- **THEN** the cases list SHALL show the empty state and the New case action

#### Scenario: The case links back to the contact
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a case whose requester is a seeded person
- **WHEN** you open the case and follow the number on the initiator card
- **THEN** you SHALL land on that person's contact page

#### Scenario: The Requester column links once a column can pick its route
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** the Cases index with the Requester column
- **WHEN** you follow a requester name
- **THEN** you SHALL land on the contact page of that person or organisation

#### Scenario: An organisation's cases on its page
@e2e exclude the Organisations folder is hidden until nextcloud-vue reads a folder's schema, so the page is reached by url only; the spec covers it by deep link and not through the index

- **GIVEN** a seeded company and a case whose `requester` is that company
- **WHEN** you open `/organisations/:id` for it
- **THEN** the card SHALL show the trade name and KvK number
- **AND** the cases list SHALL show the case
