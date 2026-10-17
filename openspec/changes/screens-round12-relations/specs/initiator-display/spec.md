## MODIFIED Requirements

### Requirement: The Contacts index lists the people dossiq knows (REQ-ID-4)

You find a person by name, number or place. The manifest page `Contacts`
(route `/contacts`, type `index`, register `dossiq`, schema `brpPerson`)
SHALL follow board DqContacten: a count line naming the BRP as the source, an
Add person button, the quick filters All and With data protection
(`indicatieGeheim`), and the columns Name (initials, with street, number and
city under it), BSN, Place of residence and Description. The BSN SHALL show
masked to its last four characters; the full number is shown only on the
contact page, where showing it is logged. The view action of a row SHALL open
`ContactDetail`. The page SHALL offer saved views and the object sidebar, and
SHALL NOT carry a `folderSidebar`.

#### Scenario: The BSN is masked in the list
@e2e exclude measured against board DqContacten by the round 12 pixel check, not an e2e flow

- **GIVEN** a seeded `brpPerson` row with BSN 123457609
- **WHEN** you open Contacts
- **THEN** the BSN column SHALL read *****7609

#### Scenario: Find a person by name
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded `brpPerson` row with display name Jansen
- **WHEN** you open Contacts and type Jansen in the search box
- **THEN** the list SHALL show that row with its citizen service number
