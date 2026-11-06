# example-set-social-search-contact Specification

## Purpose
What dossiq does for example set social search contact. The requirements were first written by the change `example-set-social-search-contact` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-example-set-social-search-contact/`) and cover: The example data carries what the social, search and contact boards draw.

## Requirements

### Requirement: The example data carries what the social, search and contact boards draw

The jeugdwet package's example case "Melding Jeugdwet gezin Oosthaven" SHALL carry `legalBasis` "Jeugdwet artikel
2.3", `initiatorDisplayName` "Huisarts, met toestemming van de ouders", `assignee` `fatma.kaya` (its regisseur, also its
role participant) and a `deadline` 35 days after its start date. The Zuiddrecht example set SHALL declare one public
saved search on the search page (`scope: source:search`, `search: "Lindelaan"`), owned by one of the set's own accounts,
with `alert: {operator: gte, threshold: 12, recipients: [user:<owner>]}`, the owner being the one recipient a
saver may name without being an administrator, and SHALL seed Sanne de Vries as a `brpPerson` with every field from the
cast.

#### Scenario: the installed set
- **GIVEN** the Zuiddrecht example set installed with the jeugdwet package's examples
- **THEN** the jeugdwet case names its legal basis, initiator and regisseur, the search page offers the saved search
  "Lindelaan" with its alert, and the contact list holds Sanne de Vries
