## ADDED Requirements

### Requirement: A team is a Nextcloud group, and being the team grants nothing (REQ-ACC-10)

dossiq SHALL have one team model: a team is a Nextcloud group, and who belongs
to it is Nextcloud's answer (`IGroupManager`), never a member list dossiq
keeps. Every place dossiq names a team SHALL hold a Nextcloud group id: the
case's `assignedGroup`, the case type's `handling.defaultGroup` and
`handling.teams`, `assigneeNarrowing.allowedGroups`, an organisation role's
`ncGroupId`, and the team a handover names.

Being a case's team SHALL be assignment, not permission. `assignedGroup` SHALL
NOT be read by any access decision; who may read or change a case stays the
grant on the object and the case type's rights matrix (REQ-ACC-01, REQ-ACC-05).
An organisation role (`organisatieRol`) stays the unit of the mandate matrix
and the rights matrix, and reaches a team only through its `ncGroupId`.

#### Scenario: The case's team is stored as a group id
@e2e tests/e2e/case-parties.spec.ts
- **GIVEN** a Nextcloud group exists
- **WHEN** a case is given that group as its team
- **THEN** the stored `assignedGroup` SHALL be the group id
- **AND** the case's personal assignee SHALL be unchanged

#### Scenario: The schema declares a group, not a reference
@e2e exclude A declaration on the shipped register, pinned in tests/Unit/Settings/RegisterSchemaGroupFieldsTest.php for the live and the mock register.
- **GIVEN** the shipped live and mock registers
- **WHEN** the case schema's `assignedGroup` is read
- **THEN** it SHALL declare `referenceType` `nextcloud-group`
- **AND** it SHALL declare no `$ref` and no `format`
