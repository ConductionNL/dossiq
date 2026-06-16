# Design: migrate-role-routing-to-or-rbac

## Context

Procest's current step routing flow (client-side, display-only):

```
User opens case detail / task list
  → workflow.js fetchAvailableTransitions()
    → loads all workflowStep / workflowTemplate objects (no server-side filter)
    → client resolves user's roles: case.roles[] → roleType UUID → roleType.name
    → filters transitions.allowedRoles contains user's roleType UUID
    → filters steps.assigneeRole === user's roleType UUID
    → result: filtered list displayed in UI
```

After migration, the flow MUST include server-side enforcement:

```
User requests step / transition data
  → ObjectService::getObjects(register, 'workflowStep', filters)
    → MagicRbacHandler::applyRbacFilters(queryBuilder, userId, groups)
      → evaluates schema.authorization block against user's NC group memberships
      → returns only steps the user is authorized to read
  → client-side display filtering may still run for UX purposes
    but server already guarantees the result set is authorization-correct
```

## The roleType → NC Group ID Bridge

OR's RBAC evaluates Nextcloud group IDs. Procest's workflow definitions reference OR
`roleType` UUIDs. The bridge is the `ncGroupId` property on `roleType`:

```json
// roleType object in OR (procest register)
{
  "name": "Vergunningverlener",
  "caseType": "uuid-of-omgevingsvergunning",
  "genericRole": "handler",
  "ncGroupId": "vergunningverleners"   // ← new required property
}
```

The `ncGroupId` is set by the procest admin when configuring role types in the admin settings
panel. It maps a domain-level role ("Vergunningverlener") to the NC group that holds that role
in the organization ("vergunningverleners"). One-to-one mapping is the common case; a roleType
with `ncGroupId: null` means "unassigned to a group" and is treated as accessible to all
authenticated users (matching the existing behaviour for roles with no assigneeRole configured).

## File-by-File Migration Plan

### lib/Settings/procest_register.json — ADD ncGroupId to roleType, ADD authorization to step schemas

**roleType schema** — add `ncGroupId` property:

```json
"ncGroupId": {
  "type": "string",
  "nullable": true,
  "description": "Nextcloud group ID that holds this role. Used by OR RBAC to enforce step-level access. Must be a valid NC group ID (IGroupManager::groupExists returns true). Null = role not yet mapped to a group."
}
```

**workflowStep schema** — add an `authorization` block referencing the resolved group. The
authorization block is dynamic — it references a named role defined at the register level
that OR expands at query time:

```json
"x-authorization": {
  "read":   [{ "role": "step.assigneeGroup" }],
  "update": [{ "role": "step.assigneeGroup" }],
  "delete": [{ "role": "admin" }]
}
```

The named role `step.assigneeGroup` is resolved by OR's RBAC from the step's
`assigneeRole` → `roleType.ncGroupId` chain. If `ncGroupId` is null (role not yet mapped),
OR RBAC falls back to "accessible to all authenticated users in the register."

Note: if OR's register-level named role expansion is not yet implemented in `rbac-scopes`,
an interim approach is to add the `authorization` block as a static list of group IDs
populated when a workflow is published. The design.md will track which approach is used
during the apply phase.

### lib/Settings/procest_register.json — procest_register authorization fallback

As an interim that works with the current `rbac-scopes` implementation:

Add a static `authorization` block to the `workflowStep` schema with a fallback group (e.g.
all users in the procest tenant group). This establishes the OR RBAC path. The dynamic
group-per-step expansion becomes a follow-on OR feature when `rbac-scopes` supports
register-level named role definitions.

### lib/Listener/KpiCacheInvalidationListener.php — VERIFY ONLY (no code change expected)

**Current**: listens on `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`.
Calls KPI cache invalidation logic.

**Compliance check:**

1. Does the listener body call `$groupManager->isInGroup()` or any access decision? → Expected: NO.
2. Does the listener call `$objectService->getObject()` or similar OR reads on the event's
   object before deciding to act? → If yes: verify these calls respect OR RBAC (i.e. the
   listener runs as a system user with read access to the relevant register, not as the
   triggering user).
3. Does the listener emit to any parallel audit store? → Expected: NO (covered by
   `consume-or-audit-trail-fleet-wide`).

If all three checks pass: document compliance in spec. If any fails: file a corrective task.

### src/views/settings/components/StepConfigPanel.vue — ADD ncGroupId display

The admin settings panel for step configuration (`StepConfigPanel.vue`) shows step properties.
Add a read-only display field "NC Group" that shows the `ncGroupId` of the step's resolved
roleType. No edit in this panel — group mapping is configured on the roleType object's own
settings page.

### openspec/specs/role-based-step-routing/spec.md — NO CHANGES

The existing spec body is not modified. This migration changes the enforcement mechanism;
it does not change the observable requirements. The spec's scenarios remain valid as-is
(Vergunningverlener sees their steps; Behandelaar does not).

## Backwards Compatibility

- The `assigneeRole` and `allowedRoles` UUID references in stored workflow definitions are
  NOT changed. They remain roleType UUIDs for import/export portability.
- The client-side role filter in workflow.js continues to run for UX purposes (instant filter
  without a round-trip). OR RBAC is the enforcement layer; client filtering is convenience.
- roleTypes that do not yet have an `ncGroupId` set behave as before: accessible to all
  authenticated users on the case (no group restriction applied by OR RBAC).

## OR RBAC Authorization Block Format Reference

From `openregister/openspec/specs/rbac-scopes/spec.md`:

The `authorization` JSON block in a schema definition follows the four-level hierarchy:
register > schema > object > property. Schema-level scopes control CRUD per schema.
Object-level scopes use `match` conditions for row-level refinement.

Group IDs in the block are evaluated by `PermissionHandler::hasGroupPermission()` which calls
`OCP\IGroupManager::isInGroup($userId, $groupId)` — the single trusted NC group membership
check.

## Seed Data

No new register definitions are added. Changes to `procest_register.json`:
- `roleType` schema: adds `ncGroupId` (nullable string, no migration needed — existing rows
  simply have the field absent/null)
- `workflowStep` schema: adds `authorization` block (applied to new queries only; existing
  data rows unaffected until an admin configures the `ncGroupId` mappings)

## Related ADRs

- **ADR-022** (primary) — mandate for this migration; "app-local RBAC on OR objects" anti-pattern.
- **ADR-023** — action RBAC vs data RBAC boundary; step routing computation (app-side) vs
  step access enforcement (OR-side).
- **ADR-005** (security) — per-object authorization; all data fetches through OR's ObjectService.
- **ADR-008** (testing) — PHPUnit + integration tests required for the new enforcement path.
