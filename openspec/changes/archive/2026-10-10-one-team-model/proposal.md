---
kind: code
---

# Proposal: one-team-model

Ruben's decision of 2026-10-09: dossiq has one team model, and a team is a
Nextcloud group.

## Why

Today dossiq has two. `case.assignedGroup` is declared
`{"format": "uuid", "$ref": "organisatieRol"}`, a reference to an
organisation role row in the register. Everything else that speaks of a team
uses a Nextcloud group id:

- `handling.defaultGroup` on the case type, read by `AssigneeResolver::resolveTeam()`
  as the fallback for the very same field;
- `TeamDirectory`, the handover (`InternalHandover`) and the custody chain,
  which write a group id into `assignedGroup` and ask `IGroupManager` who is in it;
- `handling.teams` on the case type (case-type-handling-teams, dossiq#3532);
- the board `DqAfdelingen`, "teams uit Nextcloud-groepen".

So a case that was handed over holds a group id in a field declared as a uuid
reference, a case picked on the case page holds a role uuid that no membership
check can read, and the two never meet. The e2e fixtures carry the scar: they
seed organisation roles to satisfy the uuid format and then ask the custody
chain about group membership of a uuid.

## What changes

- `case.assignedGroup` stores a Nextcloud group id: `{"type": "string",
  "referenceType": "nextcloud-group"}`, no `format`, no `$ref`. Still titled
  Team, still facetable, still optional. The case schema moves to 1.38.0 and
  the register to 0.20.21 (live and mock register).
- The form a case is created and edited in offers a Nextcloud group picker,
  which nextcloud-vue renders for `referenceType: nextcloud-group`.
- The Cases index Team column reads `assignedGroup` itself; the `extend` on
  the reference goes.
- `organisatieRol` gains `ncGroupId`, the Nextcloud group the role belongs
  to. It is the one link between a role and a team, the same name
  `roleType.ncGroupId` already uses for the same idea.
- The resident's team name (`assignedGroupPublicName`) is read from the
  organisation role bound to the case's group, through an OpenRegister lookup
  reference, instead of from the referenced row.
- A refusal destination (department and role, typed on the case type) resolves
  to the group of the matching organisation role. A role without a group
  leaves the refused case unassigned, as a role that did not exist did before.
- `assigneeNarrowing.allowedGroups` on the case type is a list of Nextcloud
  group ids, and its picker says so.
- A repair step and `occ dossiq:teams:migrate` move existing cases from an
  organisation role uuid to that role's group. A role with no group, or whose
  group does not exist, is not guessed: the case keeps its value and is
  listed by the command and in the log.

## Out of scope

- The My team view's queue is not narrowed to the reader's teams. It never
  was (it lists every unclaimed open case); narrowing it needs a filter token
  for "the groups I am in" in nextcloud-vue's object-list widget, and is now
  possible because the field holds group ids.
- The inline editor of the case page's Data and Seats panels
  (`CnObjectDataWidget`) has no group picker in nextcloud-vue 2.75; it edits
  the group id as text until it does. The create and edit dialogs have one.
- The custody chain's past holdings keep the unit they recorded. They are
  history, not the current team.
- The open-case counts in the "Case types in my menu" picker ship in a
  separate change stacked on case-type-handling-teams.

## Impact

- `lib/Settings/dossiq_register.json`, `lib/Settings/dossiq_mock_register.json`,
  `lib/Settings/register.d/61-mandaat-matrix.json`,
  `lib/Settings/register.d/37-intake-triage.json`,
  `lib/Settings/register.d/46-demo-cases-english.json`
- `lib/Service/Team/CaseTeamMigration.php` (new),
  `lib/Repair/MigrateCaseTeamsToGroups.php` (new),
  `lib/Command/MigrateCaseTeamsCommand.php` (new), `appinfo/info.xml`
- `lib/Service/Routing/RefusalOutcome.php`, docblocks in `AssigneeResolver`,
  `IntakeFanOut`, `CreateTaskHandler`
- `src/manifest.json`
- Specs: `role-routing-via-or-rbac` (team assignment modified, two requirements
  added), `case-access-control` (REQ-ACC-10 added).
