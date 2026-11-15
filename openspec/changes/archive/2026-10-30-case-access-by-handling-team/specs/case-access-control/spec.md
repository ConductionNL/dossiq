## RENAMED Requirements

- FROM: `### Requirement: A team is a Nextcloud group, and being the team grants nothing (REQ-ACC-10)`
- TO: `### Requirement: A team is a Nextcloud group, a case type's handling team reads its cases, and the handler acts after claiming (REQ-ACC-10)`

## MODIFIED Requirements

### Requirement: A team is a Nextcloud group, a case type's handling team reads its cases, and the handler acts after claiming (REQ-ACC-10)

dossiq SHALL have one team model: a team is a Nextcloud group, and who belongs
to it is Nextcloud's answer (`IGroupManager`), never a member list dossiq
keeps. Every place dossiq names a team SHALL hold a Nextcloud group id: the
case's `assignedGroup`, the case type's `handling.defaultGroup` and
`handling.teams`, `assigneeNarrowing.allowedGroups`, an organisation role's
`ncGroupId`, and the team a handover names.

Decided by Ruben on 2026-10-11, reversing the earlier rule that being the team
grants nothing: the handling teams of a case type (`handling.defaultGroup` and
`handling.teams`, read through `CaseTypeHandling::teams()`) SHALL read and
update the cases of that case type, and the case's `assignee` SHALL read and
update that case. Delete SHALL stay with administrators. OpenRegister's
administrator and owner bypasses stay. A signed in user who is none of these
SHALL NOT read the case, list it, or change its files.

The rule SHALL be declared on the case schema as OpenRegister conditional
entries and evaluated by OpenRegister (REQ-ACC-01): one entry per handling
team, `{"group": <team>, "match": {"caseType": {"$in": [<case type uuids>]}}}`,
and one assignee entry, `{"group": "authenticated", "match": {"assignee": "$userId"}}`.

Amended by Ruben on 2026-10-16, "claim before acting", keeping the behaviour
#3966 shipped: the handling team READS the cases of its case type; the case's
HANDLER (its `assignee`) EDITS it. A team member who wants to act on a case
claims it first, which makes them its handler. dossiq's own per-case checks
SHALL enforce this: every act on a case (a status move, an ending, a gesture,
a document, a publication) SHALL be refused with 403 to a reader who is not
its handler, with the sentence that says to claim it first, and the acts read
SHALL list every act as refused for such a reader rather than hide it
(REQ-ACC-11 of a-handling-team-opens-its-unclaimed-cases). The update right
the team entries carry at the register level is what lets a team member write
the claim itself; it SHALL NOT be read by dossiq as leave to act on the case.
Administrators keep their bypass.
`CaseTeamAccessProjector` SHALL compute the team entries from every case type
row and write them onto the live case schema after every register import, when
a case type is published and when a case type row is saved.

`assignedGroup` on a case SHALL still grant nothing: being a case's team is
assignment, and only the case type's handling teams grant. An organisation role
(`organisatieRol`) stays the unit of the mandate matrix and the rights matrix,
and reaches a team only through its `ncGroupId`. The rights matrix
(REQ-ACC-05) stays declared for a later refinement and is not evaluated yet.

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

#### Scenario: A handling team member reads the cases of its case type and may claim one
@e2e exclude The rule is OpenRegister's evaluation of a block dossiq writes. The block is pinned in tests/Unit/Service/Access/CaseTeamAccessRulesTest.php and its evaluation in openregister's PermissionHandlerHandlingTeamRuleTest; the live proof on the review instance is in the PR body.
- **GIVEN** a case type whose handling teams are `["vergunningen"]`
- **AND** a case of that type, owned and assigned by somebody else
- **WHEN** a member of `vergunningen` reads it, and claims it
- **THEN** both SHALL be allowed
- **AND** a delete by that member SHALL be refused

#### Scenario: A team member acts only after claiming
@e2e exclude dossiq's per-case check; asserted in tests/Unit/Controller/CaseActsControllerContractTest.php and tests/Unit/Controller/WOOAssessmentControllerAuthorizationTest.php.
- **GIVEN** a case of a type handled by `vergunningen`, assigned to `bea`
- **WHEN** `jan`, a member of `vergunningen`, tries to move, end or publish it
- **THEN** each act SHALL be refused with 403 and "Claim this case first: only its handler acts on it."
- **AND** once `jan` has claimed the case, the same acts SHALL be allowed

#### Scenario: A user in no team and not assigned is refused the case and its files
@e2e exclude Same block and evaluation as above; the files endpoint reads the object's update rule (openregister ObjectFileAccess::mayUpdate), asserted in openregister's PermissionHandlerHandlingTeamRuleTest.
- **GIVEN** a signed in user in no group
- **WHEN** they list cases, read one, or open its folder
- **THEN** the list SHALL be empty, the read SHALL be refused
- **AND** the folder SHALL NOT answer `canChange: true`

#### Scenario: The assignee reads a case outside their teams
@e2e exclude Same block and evaluation as above.
- **GIVEN** a case assigned to `bea` whose case type names no team bea is in
- **WHEN** bea reads it
- **THEN** the read SHALL be allowed

## ADDED Requirements

### Requirement: The dossiq register is closed to anybody outside its staff group (REQ-ACC-11)

The dossiq register SHALL declare a register level `authorization` block that
grants read, create, update and delete to `dossiq-medewerkers` and to the
background service group `dossiq-background-service`, so that OpenRegister
applies it to every dossiq schema that declares no block of its own. A dossiq
schema that declares its own block SHALL NOT grant any action to
`authenticated` or `public`; where it restricts a write to administrators or
another group, that restriction stays. `ProvisionAssignedGroups` SHALL
provision `dossiq-medewerkers`.

#### Scenario: Every dossiq schema is closed to an unlisted user
@e2e exclude A declaration on the shipped register, swept in tests/Unit/Settings/RegisterAccessDeclaredTest.php over the fragment-merged live register.
- **GIVEN** the fragment-merged dossiq register
- **WHEN** every schema's effective block is read, its own or the register's
- **THEN** none SHALL grant an action to `authenticated` or `public`
- **AND** every block SHALL name the background service group for the writes the background jobs make

### Requirement: Nobody loses dossiq on the upgrade that closes it (REQ-ACC-12)

A repair step SHALL add to `dossiq-medewerkers` every member of a group that
is a handling team of a case type and every user that is the assignee of a
case. It SHALL run once per instance and record that it ran, so that a member
an administrator removes afterwards is not put back.

#### Scenario: The current handlers and assignees are seeded once
@e2e exclude A repair step; asserted in tests/Unit/Repair/SeedStaffGroupTest.php.
- **GIVEN** a case type handled by `vergunningen`, with members `bea` and `jan`
- **AND** a case assigned to `piet`
- **WHEN** the repair step runs
- **THEN** `bea`, `jan` and `piet` SHALL be members of `dossiq-medewerkers`
- **AND** a second run SHALL add nobody
