## MODIFIED Requirements

### Requirement: Cases and tasks carry a team assignment

You assign a case or a task to a team, not only to a person. Assigning a team
SHALL NOT clear the personal assignee. A team is a Nextcloud group (one-team-model).

**The case half.** Schema `case` carries `assignedGroup`, a Nextcloud group id
declared `{"type": "string", "referenceType": "nextcloud-group"}`, titled
Team, facetable, optional. It SHALL NOT declare a `$ref` or a `format`. The
Cases index SHALL show a Team column that reads `assignedGroup` itself, and
the form a case is created or edited in SHALL offer the team as a picker over
Nextcloud groups.

**The task half moved to the engine and changed shape.** `caseTask` is
deleted, so there is no schema property to declare. The engine's equivalent is
`candidateGroups`, and it is a LIST, not a single reference. That is a wider
idea rather than a rename: a task offered to two teams is two rows on the
engine and could only ever be one on the register.

dossiq still writes one team. `CreateTaskHandler` carries the case's
`assignedGroup` onto the task it creates, read through `referenceId()` so a
client that still sends an expanded object does not write the literal
"Array", and `EngineTaskGateway::toEnginePayload()` puts that one value into
`candidateGroups` as a list of one. The engine's candidate groups are
Nextcloud groups, so the case's team and the task's pool are now the same
kind of thing.

What a reader lost is the column and the facet. The Tasks index has neither,
and this is a gap rather than a tidy-up. Both stood on one `$ref` per row:
a column that reads one name has nothing to read on a list, and the sidebar's
facets are computed by OpenRegister for a register and a schema, which the
page no longer declares. `TaskDetailView` SHALL show the teams on the task
page instead, comma separated, because a handler deciding whether to pick a
task up needs to know whose queue it is in.

The second scenario below asserts the Team column on the Tasks index. It is
false as written, and left byte-identical: gate 19 asks every modified
scenario for a Playwright citation and this change ships no test for it.

#### Scenario: Assign a case to a team
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a Nextcloud group Team Permits exists
- **WHEN** you set Team Permits as the team of a case
- **THEN** the case SHALL store the group id of Team Permits in `assignedGroup`
- **AND** the Cases index SHALL show it in the Team column for that case
- **AND** the Team facet SHALL list it with a count of one

#### Scenario: Assign a task to a team
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** an open task on a case
- **WHEN** you set its team to Team Permits
- **THEN** the Tasks index SHALL show the team on that row and the assignee SHALL stay as it was

## ADDED Requirements

### Requirement: An organisation role names its Nextcloud group (REQ-TEAM-02)

`organisatieRol` SHALL carry `ncGroupId`, the Nextcloud group the role belongs
to, declared `{"type": "string", "referenceType": "nextcloud-group"}`. It is
the only link between an organisation role and a team; dossiq SHALL NOT infer
one from `team`, `roleName` or a display name.

Two readers use it. A refusal destination (department and role on the case
type) SHALL resolve to the `ncGroupId` of the organisation role matching that
department and role name, and a matching role without one SHALL leave the
refused case unassigned and log it. The resident's team name
(`assignedGroupPublicName`) SHALL be the `publicName` of the organisation role
whose `ncGroupId` equals the case's `assignedGroup`, and SHALL be empty when no
role is bound to that group or the case has no team.

#### Scenario: A refused case goes to the group of the destination role
@e2e exclude RefusalOutcome resolves the group in PHP over a stored role; asserted in tests/Unit/Service/Routing/RefusalOutcomeTest.php with a store fake.
- **GIVEN** a case type whose refusal destination is department Burgerzaken, role Intake
- **AND** an organisation role Intake in Burgerzaken with `ncGroupId` `burgerzaken-intake`
- **WHEN** a case of that type is refused
- **THEN** the case's `assignedGroup` SHALL be `burgerzaken-intake`

#### Scenario: A destination role without a group leaves the case unassigned
@e2e exclude Same seam as above; asserted in tests/Unit/Service/Routing/RefusalOutcomeTest.php.
- **GIVEN** the same destination, and the role carries no `ncGroupId`
- **WHEN** a case of that type is refused
- **THEN** the refusal SHALL be recorded
- **AND** the case's `assignedGroup` SHALL NOT be written

#### Scenario: The resident reads the public name of the role bound to the team
@e2e exclude A declarative OpenRegister calculation over a lookup reference; the declaration is pinned in tests/Unit/Settings/RegisterSchemaGroupFieldsTest.php and the evaluation is OpenRegister's.
- **GIVEN** an organisation role with `ncGroupId` `vergunningen` and public name Team Vergunningen
- **WHEN** a case with `assignedGroup` `vergunningen` is saved
- **THEN** its `assignedGroupPublicName` SHALL be Team Vergunningen
- **AND** a case with no team SHALL carry an empty `assignedGroupPublicName`

### Requirement: Existing cases move to the group their role names (REQ-TEAM-03)

A case stored before this change holds an organisation role uuid in
`assignedGroup`. dossiq SHALL convert it to that role's `ncGroupId` when the
role names one and the group exists, through one service run by the repair
step `MigrateCaseTeamsToGroups` on upgrade and by `occ dossiq:teams:migrate`.

dossiq SHALL NOT guess. A case whose role has no `ncGroupId`, whose role names
a group that does not exist, or whose value is neither a group nor a role
SHALL keep its value and SHALL be reported: the command lists each such case
with its value, the role name and the reason, and the repair step logs how
many there are and which command lists them. The command MAY show groups whose
id or display name matches the role as a hint, and SHALL NOT apply a hint.
`--dry-run` SHALL report without writing. Running the migration again SHALL
change nothing it already changed.

#### Scenario: A case whose role names a group is converted
@e2e exclude A repair step over stored cases; asserted in tests/Unit/Service/Team/CaseTeamMigrationTest.php with a store fake and a group manager mock.
- **GIVEN** a case whose `assignedGroup` is the uuid of a role with `ncGroupId` `vergunningen`
- **AND** the group `vergunningen` exists
- **WHEN** the migration runs
- **THEN** the case's `assignedGroup` SHALL be `vergunningen`

#### Scenario: A role without a group is reported, not guessed
@e2e exclude Same seam; asserted in tests/Unit/Service/Team/CaseTeamMigrationTest.php.
- **GIVEN** a case whose `assignedGroup` is the uuid of a role Team Handhaving with no `ncGroupId`
- **AND** a group `handhaving` exists
- **WHEN** the migration runs
- **THEN** the case SHALL keep the role uuid
- **AND** the report SHALL list the case with reason `role-has-no-group` and `handhaving` as a hint only

#### Scenario: Running it twice changes nothing more
@e2e exclude Same seam; asserted in tests/Unit/Service/Team/CaseTeamMigrationTest.php.
- **GIVEN** the migration ran and converted one case
- **WHEN** it runs again
- **THEN** it SHALL write nothing
- **AND** it SHALL count that case as already a group

#### Scenario: A dry run writes nothing
@e2e exclude Same seam; asserted in tests/Unit/Service/Team/CaseTeamMigrationTest.php.
- **GIVEN** a case that would be converted
- **WHEN** the migration runs with `--dry-run`
- **THEN** the report SHALL count it as converted
- **AND** the stored case SHALL be unchanged
