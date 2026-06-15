# role-routing-via-or-rbac Specification

---
status: proposed
---

## Purpose

Bring procest's role-based step routing onto OpenRegister's RBAC group model
(ADR-022 / ADR-023). The canonical role identifier becomes a Nextcloud group id
carried on each `roleType` via a new `ncGroupId` field. At workflow-publish time
procest resolves every transition's assignee role to its `ncGroupId` and freezes
the literal group id(s) into the transition's `authorization` list — the exact
declarative-gate format OpenRegister PR #153 enforces. Enforcement then consumes
OR's single trusted membership check (`IGroupManager`), replacing procest's
bespoke role-resolution gate. References `consume-or-rbac-fleet-wide`.

@e2e exclude Backend RBAC-enforcement migration — the ncGroupId bridge, publish-time authorization resolution (WorkflowStepAuthorizationResolver + WorkflowDefinitionService::publish), and the IGroupManager-based transition gate in StatusTransitionService are covered by PHPUnit (`tests/Unit/Service/WorkflowStepAuthorizationResolverTest.php`, `tests/Unit/Service/StatusTransitionGroupAuthTest.php`); the only UI surface is the read-only "NC Group ID" admin field on the roleType editor (RoleTypesTab.vue), an admin-settings convenience with no end-user workflow.

## ADDED Requirements

### Requirement: roleType Schema Carries an ncGroupId Bridge Field

The `roleType` schema in `procest_register.json` MUST include a nullable
`ncGroupId` string property that binds a procest role to a Nextcloud group id,
the canonical OR RBAC role identifier. A null/empty `ncGroupId` means the role is
unmapped and imposes no group restriction (open to all authenticated users),
matching the pre-migration default.

#### Scenario: roleType declares ncGroupId

- GIVEN the `roleType` schema in `procest_register.json`
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
least one listed group. Procest MUST NOT use a bespoke role-resolution scheme to
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
- WHEN any procest-register object is mutated
- THEN the listener invalidates the KPI cache
- AND its body MUST NOT call `$groupManager->isInGroup()` or write a permission store

---

### Requirement: No Parallel Permission Store for OR-Owned Objects

Procest MUST NOT introduce a database table or OR schema whose purpose is to
store access permissions for OR-owned objects. Access configuration lives in the
`roleType.ncGroupId` bridge and the transition `authorization` lists.

#### Scenario: No permission store added

- GIVEN the procest app after this change
- WHEN its schemas and tables are inspected
- THEN no new `*Permission*` / `*AccessRule*` schema or `*_perm*` table MUST exist

## Non-Requirements

- This change does NOT make OpenRegister enforce the transition gate on
  `saveObject`. OR's declarative transition-authorization (PR #153) only fires on
  a schema that carries `x-openregister-lifecycle`. `case.status` is a UUID
  reference to a per-caseType `statusType` (a dynamic, per-caseType state machine)
  and has no fixed lifecycle table, so OR cannot intercept its transitions.
  Procest therefore enforces the SAME OR group model in-app at the transition
  boundary (`StatusTransitionService`) using `IGroupManager`. Closing this fully —
  so OR rejects an unauthorized case-status save server-side — needs OR to support
  a per-object/workflow-driven dynamic transition table. This is a residual OR gap.
- This change does NOT convert stored `assigneeRole`/`allowedRoles` UUID
  references; they remain roleType UUIDs for import/export portability and are
  resolved to group ids at publish time.
- This change does NOT modify the `role-based-step-routing` spec body; the
  observable routing behaviour is preserved, only the enforcement mechanism moves
  to the OR group model.

## Dependencies

- OpenRegister PR #153 — declarative per-transition `authorization` gate +
  `PermissionHandler::isTransitionAuthorized` (the format procest writes and whose
  semantics the in-app gate mirrors).
- `openregister/openspec/specs/rbac-scopes`, `auth-system` — OR RBAC contracts.

## Cross-References

- **procest/openspec/specs/role-based-step-routing** — enforcement mechanism note:
  step access is enforced via OR's group model using `roleType.ncGroupId` as the
  NC group identifier (spec body unchanged).
