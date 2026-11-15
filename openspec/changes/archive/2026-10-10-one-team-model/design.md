# Design: one-team-model

## D-1. The field holds the group id, nothing else

`case.assignedGroup` becomes `{"type": "string", "referenceType":
"nextcloud-group"}`. The group id is what every reader already needs:
`IGroupManager::isInGroup()`, `handling.defaultGroup`, the handover and the
custody chain all take it as is. A role uuid needs a second lookup in each of
them, which nobody wrote.

`referenceType: nextcloud-group` is the declaration nextcloud-vue reads to
render a group picker in `CnFormDialog` and to store the id. It is also what
`RegisterSchemaGroupFieldsTest` now pins, together with the absence of `$ref`
and `format`: either of those back would make OpenRegister refuse every group
id with "should match format uuid", which is how the handover e2e died before.

The older decision this replaces said a group id on the case would "blur
assignment with permission". It does not, and REQ-ACC-10 says why: being the
case's team grants nothing. Access still comes from the grant on the object
and the case type's rights matrix.

## D-2. How a role maps to a group

**Today it does not.** `organisatieRol` has `roleName`, `department`, `team`
(free text), `publicName` and no group. No code joins a role to a group, no
naming convention is written down anywhere, and OpenRegister organisations are
not used for teams. The only group binding in the register is on `roleType`
(`ncGroupId`), a different schema with a different job.

So this change adds the binding instead of inventing one: `organisatieRol.ncGroupId`,
the Nextcloud group the role belongs to. Same name as on `roleType`, same
meaning, set by an administrator. Matching `team` or `roleName` against
group ids or display names would be a guess, and a wrong guess moves a case
to a team that never sees it. Those matches are shown as hints by the occ
command and never applied.

## D-3. The migration

`CaseTeamMigration::run(bool $apply)` walks every case (unscoped, as system,
in pages of 200) and sorts each by what its `assignedGroup` holds:

| Holds | Outcome |
|---|---|
| nothing | skipped, not counted |
| an existing Nextcloud group id | already a group, left alone |
| the uuid of an organisation role whose `ncGroupId` names an existing group | converted to that group id |
| the uuid of a role with no `ncGroupId` | unmapped, reason `role-has-no-group` |
| the uuid of a role whose `ncGroupId` names no group | unmapped, reason `role-group-missing` |
| anything else | unmapped, reason `unknown-team` |

An unmapped case keeps its value. It is reported, never cleared and never
guessed. Running it twice changes nothing the first run did not: a converted
case now holds a group id and lands in the second row.

The repair step `MigrateCaseTeamsToGroups` runs it with `apply` on upgrade,
after the register import (so the schema already accepts a group id) and
before `BackfillCaseCustody` (so a holding it opens records the group). It
logs one warning naming the count of unmapped cases and the command to list
them. `occ dossiq:teams:migrate` runs the same service, `--dry-run` reports
without writing, and both list every unmapped case with its role and the hint.
An administrator who fills in `ncGroupId` on a role runs the command again.

POST-MIGRATION only, like `BackfillCaseCustody`: a fresh install has no cases.

## D-4. The resident's team name

`assignedGroupPublicName` used to read `@ref.assignedGroup.publicName` off the
referenced role. A group has no public name, and the internal group name must
not reach a resident (it may name a person or a code). So the case declares an
OpenRegister lookup reference `team` on `organisatieRol` with
`ncGroupId = @self.assignedGroup`, and the calculation reads its `publicName`
only when the row's `ncGroupId` equals the case's group. The guard matters: a
lookup on an empty value must not hand one case another team's name. When
several roles bind one group, the first one OpenRegister returns answers; put
the public name on each of them.

## D-5. Refusal destination

`RefusalOutcome::teamFor()` still joins the declared department and role on
`organisatieRol`, and now writes the matched row's `ncGroupId`. A row without
one leaves the refused case unassigned and logs it, the same recoverable
outcome as a role that does not exist.

## D-6. What is left as it is

- `IntakeFanOut` writes the destination's `department` into `assignedGroup`.
  Under this model the department of an intake destination is a group id, and
  that is now what the schema accepts; before, OpenRegister refused it.
- The custody chain's `organisationUnit` already took `assignedGroup` as a
  group. Past holdings that recorded a role uuid stay as recorded.
- `AssigneeResolver::referenceId()` still accepts an expanded object, so an
  old client that sends one does not write "Array".
