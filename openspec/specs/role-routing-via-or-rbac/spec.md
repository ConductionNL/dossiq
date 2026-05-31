# role-routing-via-or-rbac Specification

## Purpose
TBD - created by archiving change migrate-role-routing-to-or-rbac. Update Purpose after archive.
## Requirements
### Requirement: Step Routing Returns Nextcloud Group IDs

The step routing resolution chain (`assigneeRole` → `roleType` → enforcement group) SHALL
terminate in a Nextcloud group ID. The `roleType` OR object MUST carry an `ncGroupId` string
property. When `ncGroupId` is set, OR's RBAC uses it as the group identifier for access
enforcement on that step's objects. When `ncGroupId` is null, access defaults to all
authenticated users on the case (open step — no group restriction).

#### Scenario: Step with assigneeRole resolves to NC group ID

- GIVEN a `workflowStep` with `assigneeRole` set to roleType UUID `rt-vergunningverlener`
- AND roleType `rt-vergunningverlener` has `ncGroupId: "vergunningverleners"`
- WHEN the routing service resolves the step's assignee group
- THEN the service SHALL return `"vergunningverleners"` (an NC group ID)
- AND `IGroupManager::groupExists("vergunningverleners")` SHALL return true

#### Scenario: Step with null ncGroupId is accessible to all authenticated users

- GIVEN a `workflowStep` with `assigneeRole` set to roleType UUID `rt-unconfigured`
- AND roleType `rt-unconfigured` has `ncGroupId: null`
- WHEN OR evaluates access on the step
- THEN the step SHALL be accessible to all authenticated users in the procest register
- AND the step SHALL NOT be restricted to any specific NC group

---

### Requirement: Access Enforcement on Routed Steps Uses OR's RBAC API

Access decisions on `workflowStep` objects stored in OR MUST be enforced by OR's
`MagicRbacHandler` via the schema's `authorization` block. The procest app SHALL NOT
implement a parallel group-membership check to gate access to these objects.

#### Scenario: MagicRbacHandler excludes steps whose assigneeRole group the user does not belong to

- GIVEN a `workflowStep` schema has an `authorization` block referencing group `behandelaars`
- AND user "jan" is NOT in group `behandelaars`
- AND user "piet" IS in group `behandelaars`
- WHEN "jan" calls `GET /api/objects/{register}/workflowStep`
- THEN the step SHALL NOT appear in jan's result set (filtered by MagicRbacHandler)
- WHEN "piet" calls the same endpoint
- THEN the step SHALL appear in piet's result set

#### Scenario: No parallel isInGroup call in the step controller

- GIVEN the procest controller or service method that fetches step objects
- WHEN the method runs
- THEN the method body SHALL NOT call `$groupManager->isInGroup()` for data-layer gating
- AND the access filtering SHALL be performed exclusively by OR's MagicRbacHandler

---

### Requirement: roleType Schema Carries ncGroupId Property

The `roleType` schema in `procest_register.json` MUST include an `ncGroupId` nullable string
property. Admin users MUST be able to set `ncGroupId` on each roleType via the procest admin
settings UI.

#### Scenario: Admin sets ncGroupId on a roleType

- GIVEN the admin is editing roleType "Vergunningverlener" in procest admin settings
- WHEN the admin sets `ncGroupId` to `"vergunningverleners"` and saves
- THEN the roleType OR object SHALL be updated with `ncGroupId: "vergunningverleners"`
- AND the step configuration panel SHALL display "NC Group: vergunningverleners" for steps
  using this roleType

#### Scenario: Validation rejects a non-existent NC group ID

- GIVEN an admin sets `ncGroupId` to `"group-that-does-not-exist"` on a roleType
- WHEN the update is submitted
- THEN the system SHOULD warn that the group does not exist in Nextcloud
  (soft validation — OR RBAC silently excludes everyone if the group is missing)
- AND the admin SHOULD be able to save anyway (to allow pre-creating the group later)

---

### Requirement: KpiCacheInvalidationListener Does Not Make Access Decisions

`KpiCacheInvalidationListener` SHALL listen only on OR's published event classes and MUST NOT perform any access-control check on the triggering object. It MUST NOT emit to any parallel audit or permission store.

#### Scenario: Listener fires on any OR object mutation without group check

- GIVEN `KpiCacheInvalidationListener` is registered on `ObjectUpdatedEvent`
- WHEN any OR object in the procest register is updated (by any user)
- THEN the listener SHALL invalidate the relevant KPI cache entries
- AND the listener body SHALL NOT call `$groupManager->isInGroup()` or any equivalent check

#### Scenario: Listener uses only OR event classes

- GIVEN `KpiCacheInvalidationListener.php` is inspected
- THEN all `use` statements for event classes SHALL point to `OCA\OpenRegister\Event\*`
- AND no procest-local event classes SHALL be used as listener triggers

---

### Requirement: No Parallel Permission Tables in Procest for OR-Owned Objects

Procest MUST NOT define database tables or OR schemas whose primary purpose is to store
access permissions for OR-owned objects. Access configuration belongs in the schema's
`authorization` block.

#### Scenario: No permission table exists after migration

- GIVEN the procest application is installed
- WHEN the database schema is inspected
- THEN no table named `*_perm*` or `*_role_access*` or similar SHALL exist in the procest
  app's set of managed tables
- AND no procest OR schema named `*Permission*` or `*AccessRule*` SHALL exist in the procest
  register

---

### Requirement: Test Contract — Routed Step Access Exercises OR RBAC End-to-End

A test MUST verify that a routed step's access decision is made by OR's RBAC API, not by
client-side filtering alone. The test MUST call the OR API directly (bypassing any frontend
filtering) and assert the correct access control outcome.

#### Scenario: Integration test verifies step is filtered by OR RBAC

- GIVEN a `workflowStep` with `assigneeRole` pointing to roleType with `ncGroupId: "vergunningverleners"`
- AND user "jan" is NOT in group `vergunningverleners`
- AND user "piet" IS in group `vergunningverleners`
- WHEN a test calls `GET /api/objects/{register}/workflowStep` authenticated as "jan"
- THEN the response SHALL NOT include the step
- WHEN the same test calls the endpoint authenticated as "piet"
- THEN the response SHALL include the step
- AND the test SHALL NOT use client-side filtering to produce this result

#### Scenario: Step routing computation still produces correct task list for UI

- GIVEN the above setup
- WHEN "piet" opens their task list in the procest frontend
- THEN the task for the step SHALL appear in piet's list (server-side RBAC allows it)
- WHEN "jan" opens their task list
- THEN the task SHALL NOT appear in jan's list (server-side RBAC already excludes it;
  client-side filter is now redundant but harmless)

