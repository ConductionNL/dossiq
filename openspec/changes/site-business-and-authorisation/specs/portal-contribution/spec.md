## ADDED Requirements

### Requirement: Dossiq serves a business audience (REQ-SBA-001)
The provider MUST list `business` among its audiences and MUST answer it with the resident
manifest: the case, message, question and request collections, the create and endpoint
actions, and the pages of `site-resident-portal-design` with the case heading "Lopende zaken
van uw bedrijf". The Woo request route MUST accept the `business` audience. The `supplier`
audience MUST keep its procurement collections. This is a new requirement.

#### Scenario: A company signs in with eHerkenning on a municipal portal
- **GIVEN** a portal whose eHerkenning provider maps the audience to `business`
- **WHEN** Jan-Willem van der Berg signs in for KVK 12345678
- **THEN** the site MUST show dossiq's case and message pages and no procurement page

#### Scenario: The procurement portal is unchanged
- **GIVEN** a portal that keeps the eHerkenning preset audience `supplier`
- **WHEN** a supplier signs in
- **THEN** the tenders, contracts, invoices, performance and messages collections MUST still be served

### Requirement: A case names the party it belongs to (REQ-SBA-002)
A case opened from a portal write MUST carry `portalParty` in portaliq's typed form: the
mandate's `onBehalfOf` when the write ran under a mandate, `kvk:<8 digits>` from the session's
`kvk` when a `business` or `supplier` session carries one, and `subject:<subjectRef>`
otherwise. It MUST NOT be derived from `identityRef` and MUST NOT hold a BSN. `mijnZaken` MUST project
it and MUST declare `mandateField: 'portalParty'`. This is a new requirement.

#### Scenario: A company's case is found through a company mandate
- **GIVEN** a case filed by an employee of KVK 12345678 with `portalParty` `kvk:12345678`
- **AND** Administratiekantoor Kramer, KVK 87654321, holding an active mandate with `holder` `kvk:87654321` and `onBehalfOf` `kvk:12345678`
- **WHEN** an employee of Kramer signs in with eHerkenning, acts for the slagerij and opens "Zaken"
- **THEN** the case MUST be listed with the mandate's label

#### Scenario: A business session without a KVK number
- **GIVEN** an eHerkenning session that carries no `kvk` (before portaliq task T0)
- **WHEN** it files a case
- **THEN** the case MUST carry `portalParty` `subject:<that session's subjectRef>`
- **AND** no company mandate MUST reach the case

#### Scenario: A mandate limited to case types
- **GIVEN** Administratiekantoor Kramer holding a mandate for KVK 12345678 with `caseTypes` the bezwaar types
- **WHEN** they list the company's cases
- **THEN** only the company's bezwaar cases MUST be listed

#### Scenario: Acting for a parent
- **GIVEN** H. Bakker's case with `portalParty` `subject:<his subjectRef>` and Linda Bakker holding a mandate with that `onBehalfOf`
- **WHEN** Linda acts for her father
- **THEN** his case MUST be listed and hers MUST NOT

### Requirement: A company case carries its branch (REQ-SBA-003)
The branch requirement of `portal-case-list-declarations` (REQ-PORTAL-011) MUST hold for the
`business` audience as well as `supplier`: a case opened from a branch-restricted session
carries `portalBranch`, and `mijnZaken` declares `branchField: 'portalBranch'`.

#### Scenario: A branch session sees its branch only
- **GIVEN** two cases of KVK 12345678, one filed for branch 000012345678 and one for another branch
- **WHEN** a session restricted to branch 000012345678 lists its cases
- **THEN** only the first case MUST be listed

### Requirement: The case history says who acted for whom (REQ-SBA-004)
An entry of `caseTimeline` for a portal write that ran under a mandate MUST name the acting
person and the party, as "{name}, namens {party}", reading the write record portaliq keeps on
the case. The represented party MUST see the same entry. This is a new requirement.

#### Scenario: The father sees what his daughter did
- **GIVEN** Linda Bakker added a document to her father's bezwaar while acting for him
- **WHEN** H. Bakker opens the case
- **THEN** "Wat er is gebeurd" MUST hold an entry naming Linda Bakker, namens H. Bakker
