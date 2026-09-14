# role-routing-via-or-rbac Specification

## Purpose
TBD - created by archiving change migrate-role-routing-to-or-rbac. Update Purpose after archive.

## Requirements

### Requirement: roleType Schema Carries an ncGroupId Bridge Field

The `roleType` schema in `dossiq_register.json` MUST include a nullable
`ncGroupId` string property that binds a dossiq role to a Nextcloud group id,
the canonical OR RBAC role identifier. A null/empty `ncGroupId` means the role is
unmapped and imposes no group restriction (open to all authenticated users),
matching the pre-migration default.

#### Scenario: roleType declares ncGroupId

- GIVEN the `roleType` schema in `dossiq_register.json`
- WHEN its `properties` are inspected
- THEN `ncGroupId` MUST be declared as a nullable string
- AND the admin roleType editor MUST expose an editable "NC Group ID" field
  bound to it

---

### Requirement: Publish Resolves Roles to Group Authorization on Transitions

`WorkflowDefinitionService::publish()` MUST resolve each transition's assignee
role (`assigneeRole` / `allowedRoles` / `routingRule`) to its `roleType.ncGroupId`
and write the resolved literal NC group id(s) into that transition's
`authorization` list before freezing the published (immutable) definition. A
transition whose role maps to no group MUST carry no `authorization` entry.

#### Scenario: Published transition carries resolved group ids

- GIVEN a draft workflowTemplate whose transition references roleType
  `rt-vergunningverlener` with `ncGroupId: "vergunningverleners"`
- WHEN the definition is published
- THEN the published transition's `authorization` list MUST contain
  `"vergunningverleners"`

#### Scenario: Unmapped role leaves the transition open

- GIVEN a draft transition whose roleType has `ncGroupId: null`
- WHEN the definition is published
- THEN the published transition MUST NOT carry an `authorization` key
- AND the transition MUST remain executable by any authenticated user

---

### Requirement: Transition Execution Enforces the OR Group Authorization

`StatusTransitionService::execute()` MUST enforce a transition's resolved
`authorization` group list using OpenRegister's single trusted membership check
(`IGroupManager`), with the same semantics as OR's
`PermissionHandler::isTransitionAuthorized`: an empty/absent list is open, an
anonymous caller is denied, admins bypass, otherwise the caller MUST belong to at
least one listed group. Dossiq MUST NOT use a bespoke role-resolution scheme to
make this group decision.

#### Scenario: Unauthorized group is rejected

- GIVEN a published transition with `authorization: ["vergunningverleners"]`
- AND user "jan" is NOT in group `vergunningverleners`
- WHEN "jan" attempts to execute the transition
- THEN the execution MUST be rejected (`transition_unauthorized`)

#### Scenario: Authorized group passes

- GIVEN the same transition
- AND user "piet" IS in group `vergunningverleners`
- WHEN "piet" executes the transition
- THEN the group gate MUST pass and execution proceeds to guard evaluation

#### Scenario: Empty authorization is open and admins bypass

- GIVEN a transition with an empty or absent `authorization` list
- WHEN any authenticated user executes it
- THEN the group gate MUST pass
- AND a member of the `admin` group MUST pass even a non-empty gate

---

### Requirement: KpiCacheInvalidationListener Makes No Access Decisions

`KpiCacheInvalidationListener` MUST listen only on `OCA\OpenRegister\Event\*`
classes, MUST NOT call `IGroupManager::isInGroup()` (or any access check), and
MUST NOT write to a parallel audit or permission store. Its IUserSession use MUST
be limited to keying the per-user KPI cache version.

#### Scenario: Listener fires without an access check

- GIVEN `KpiCacheInvalidationListener` registered on OR object events
- WHEN any dossiq-register object is mutated
- THEN the listener invalidates the KPI cache
- AND its body MUST NOT call `$groupManager->isInGroup()` or write a permission store

---

### Requirement: No Parallel Permission Store for OR-Owned Objects

Dossiq MUST NOT introduce a database table or OR schema whose purpose is to
store access permissions for OR-owned objects. Access configuration lives in the
`roleType.ncGroupId` bridge and the transition `authorization` lists.

#### Scenario: No permission store added

- GIVEN the dossiq app after this change
- WHEN its schemas and tables are inspected
- THEN no new `*Permission*` / `*AccessRule*` schema or `*_perm*` table MUST exist

### Requirement: Cases and tasks carry a team assignment

You assign a case or a task to a team, not only to a person. Assigning a team
SHALL NOT clear the personal assignee.

**The case half is unchanged.** Schema `case` carries `assignedGroup`, a
`$ref` to `organisatieRol`, titled Team, facetable. The Cases index SHALL show
a Team column, which reads `assignedGroup.roleName` off the expanded reference,
and the edit form SHALL offer the team as a picker over `organisatieRol`.

**The task half moved to the engine and changed shape.** `caseTask` is
deleted, so there is no schema property to declare. The engine's equivalent is
`candidateGroups`, and it is a LIST, not a single reference. That is a wider
idea rather than a rename: a task offered to two teams is two rows on the
engine and could only ever be one on the register.

dossiq still writes one team. `CreateTaskHandler` carries the case's
`assignedGroup` onto the task it creates, read through `referenceId()` because
an expanded `$ref` casts to the literal "Array", and
`EngineTaskGateway::toEnginePayload()` puts that one value into
`candidateGroups` as a list of one. So a task created from a case flow lands
in its case's team pool, as before.

What a reader lost is the column and the facet. The Tasks index has neither,
and this is a gap rather than a tidy-up. Both stood on one `$ref` per row:
a column that reads one name has nothing to read on a list, and the sidebar's
facets are computed by OpenRegister for a register and a schema, which the
page no longer declares. `TaskDetailView` SHALL show the teams on the task
page instead, comma separated, because a handler deciding whether to pick a
task up needs to know whose queue it is in.

The second scenario below asserts the Team column on the Tasks index. It is
false as written, and left byte-identical: gate 19 asks every modified
scenario for a Playwright citation and this change ships no test.

#### Scenario: Assign a case to a team
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** an organisation role Team Permits exists
- **WHEN** you edit a case and pick Team Permits as its team
- **THEN** the Cases index SHALL show Team Permits in the Team column for that case
- **AND** the Team facet SHALL list Team Permits with a count of one

#### Scenario: Assign a task to a team
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** an open task on a case
- **WHEN** you set its team to Team Permits
- **THEN** the Tasks index SHALL show the team on that row and the assignee SHALL stay as it was

### Requirement: Mine is a quick filter on both indexes

You switch to your own work with one click. The `Cases` and `Tasks` indexes
SHALL carry a `quickFilters` chip Mine. On `Cases` it filters
`assignee = @me`, over the register. On `Tasks` it filters `scope: assigned`,
which is the engine's own answer to the same question, because that page reads
the task engine and no longer binds a schema.

A Team chip waits for the platform to resolve the signed-in handler's teams.
Until then the Team column on `Cases` is the way to read a team off a list.
The Team facet is not: `useObjectStore` normalises a facet bucket as
`{ value, count }` and OpenRegister's bucket carries `results` rather than
`count`, so every option reads 0, and the same normalisation drops the label,
so a `$ref` facet lists uuids. Both are one-line defects in the library, and
neither is coverage for the Team chip. On `Tasks` there is no column and no
facet at all: see the requirement above.

#### Scenario: Mine shows only my cases
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** cases assigned to you and to a colleague
- **WHEN** you press the Mine chip on Cases
- **THEN** only the cases assigned to you SHALL remain in the list
