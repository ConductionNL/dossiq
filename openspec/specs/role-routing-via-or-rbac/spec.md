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

