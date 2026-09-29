## ADDED Requirements

### Requirement: The case collection reaches the audiences a portal login carries (REQ-PORTAL-009)
The portal contribution MUST serve the case collection `mijnZaken` to the
`client` audience, which DigiD and eIDAS sessions carry, and to the `supplier`
audience, which eHerkenning sessions carry, as well as to `citizen`. It MUST
stay scoped by `portalSubject` for every audience.

#### Scenario: A resident signed in with DigiD sees their case
- **GIVEN** a resident with a dossiq case whose `portalSubject` is theirs, signed in to the portal with DigiD
- **WHEN** they open "My cases"
- **THEN** the dossiq case MUST be listed

#### Scenario: A company sees only its own cases
- **GIVEN** a company signed in with eHerkenning and another company's case
- **WHEN** the company's session reads the case collection
- **THEN** only the cases filed under its own subject MUST be returned

### Requirement: The case list says what is a case and when it is closed (REQ-PORTAL-010)
`mijnZaken` MUST declare `kind: 'cases'` and `closedField: 'endDate'`.

#### Scenario: An ended case is listed as closed
- **GIVEN** a resident with one running case and one case with an end date
- **WHEN** they open "My cases" in the portal
- **THEN** the running case MUST be under Open and the ended case under Closed

### Requirement: A company's case carries the branch it was filed for (REQ-PORTAL-011)
A case opened from a portal write MUST carry the branch number the portal
stamped, in `portalBranch`, and `mijnZaken` MUST declare
`branchField: 'portalBranch'`.

#### Scenario: A branch login sees only that branch's cases
- **GIVEN** a company with a case filed for branch 000012345678 and one for another branch
- **WHEN** an employee signs in with eHerkenning restricted to branch 000012345678 and opens "My cases"
- **THEN** only the first case MUST be listed

### Requirement: The case number is declared, and no case type admits it without an address check (REQ-PORTAL-012)
`mijnZaken` MUST declare `referenceField: 'identifier'`. A case type MAY carry
`portalIdentityKind`; dossiq MUST NOT offer `reference` in the case type
editor while the portal issues a reference link without checking the address
against the case.

#### Scenario: The option is visibly unavailable
- **GIVEN** a functional administrator editing a case type
- **WHEN** they look at who may reach its cases from the portal
- **THEN** "With a case number and an e-mail address" MUST be shown disabled with the sentence "Available once the portal checks the address against the case."

### Requirement: The case list says where its case types live (REQ-PORTAL-017)
`mijnZaken` MUST declare `caseTypeField: 'caseType'` and
`caseTypeSource: {register: 'dossiq', schema: 'caseType', labelField: 'title'}`,
so a portal administrator can hide a dossiq case type the portal has no
request form for (portaliq `operate-show-per-case-type`, portaliq#907).

#### Scenario: An administrator hides a case type without a form
- **GIVEN** a dossiq case type "Melding openbare ruimte" that no portal request form names
- **WHEN** a portal administrator opens the case types of their portal
- **THEN** "Melding openbare ruimte" MUST be listed under its title, and hiding it MUST keep its cases off "My cases" in that portal
