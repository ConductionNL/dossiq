# Proposal: migrate-role-routing-to-or-rbac

> BUILT 2026-06-15 (build/migrate-role-routing-to-or-rbac-2026-06-15):
> OpenRegister PR #153 landed the declarative per-transition authorization gate
> (`authorization: ["<ncGroupId>"]` enforced by `PermissionHandler::isTransitionAuthorized`).
> Procest now consumes the OR RBAC group model:
> - Added nullable `ncGroupId` to the `roleType` schema (the canonical role→group bridge).
> - `WorkflowStepAuthorizationResolver` (new service): resolves a step/transition's
>   `assigneeRole`/`allowedRoles`/`routingRule` roleType UUIDs → `roleType.ncGroupId`
>   literal NC group ids.
> - `WorkflowDefinitionService::publish()` now resolves and freezes those group ids
>   into each transition's `authorization` list at publish time (the OR #153 gate format).
> - `StatusTransitionService::execute()` enforces that `authorization` list via OR's
>   single trusted membership check (`IGroupManager`), with OR's exact semantics
>   (empty=open, anonymous denied, admin bypass) — replacing reliance on the bespoke
>   `RoleGuard` role-resolution for the group decision.
> - Admin UI: `RoleTypesTab.vue` gained an editable "NC Group ID" field + a badge.
> - `KpiCacheInvalidationListener` verified compliant (no access decisions) + annotated.
> - Tests: `WorkflowStepAuthorizationResolverTest` (role→group resolution) +
>   `StatusTransitionGroupAuthTest` (unauthorized rejected / authorized passes / open / admin).
>
> SCOPE CORRECTION (supersedes the stale note below): the design assumed `workflowStep`
> objects are standalone OR rows queried via `GET /api/objects/{register}/workflowStep`
> and filtered by `MagicRbacHandler`. They are NOT — steps/transitions are embedded
> JSON inside `workflowTemplate`, and routing gates *case status transitions*, not row
> reads. So the gate is applied to transition execution, not object listing.
>
> RESIDUAL OR GAP (genuine, flagged): OR's declarative transition-authorization (PR #153)
> only fires on a schema carrying `x-openregister-lifecycle`. `case.status` is a UUID ref
> to a per-caseType `statusType` (dynamic per-caseType state machine, no fixed lifecycle
> table), so OR cannot intercept case-status saves. Procest therefore enforces the SAME
> OR group model in-app at the transition boundary. Full server-side OR enforcement needs
> OR to support a per-object/workflow-driven dynamic transition table.

## Why

ADR-022 (Apps Consume OpenRegister Abstractions) explicitly prohibits "app-local RBAC on OR
objects — an app defining its own role/permission scheme for objects that live in OR's register."

Procest's role-based step routing currently has the correct consumer contract for computing
which group should handle a step (the existing `role-based-step-routing` spec), but the
access enforcement layer is missing:

- **`assigneeRole` and `allowedRoles` in workflow step / transition objects** carry OR
  `roleType` UUIDs. When the frontend (workflow.js) filters transitions and tasks, it does
  so entirely client-side by comparing the user's resolved roles against these UUIDs. No
  OR-side `authorization` block on the step schema gates server-side access.
- **`KpiCacheInvalidationListener`** listens on `ObjectCreatedEvent` / `ObjectUpdatedEvent` /
  `ObjectDeletedEvent` and correctly invalidates caches. This listener is compliant; it makes
  no access decisions. It is documented here only for completeness.
- **No parallel permission table or service exists** — the violation is the absence of OR
  RBAC enforcement, not the presence of a parallel implementation. Client-side role filtering
  is display-only and provides no security boundary.

The umbrella spec `consume-or-rbac-fleet-wide` (hydra) mandates that role-based access on
OR-owned objects must be enforced by OR's RBAC stack (rbac-scopes + auth-system), using
Nextcloud group IDs as the canonical role identifier.

## What

Bring procest's step routing into full OR RBAC compliance:

1. **Resolve roleType UUIDs to NC group IDs** at enforcement time. Each `roleType` OR object
   SHALL carry a `ncGroupId` property (a Nextcloud group ID). The routing service reads this
   field and builds the NC group ID that OR's RBAC uses for enforcement.
2. **Add OR `authorization` blocks to the `workflowStep` and `workflowTemplate` schemas** in
   `procest_register.json` so that OR's `MagicRbacHandler` filters step objects at the
   database level based on the requesting user's group memberships.
3. **Verify `KpiCacheInvalidationListener`** listens only on OR's published object events and
   makes no access decisions. Document compliance in this spec. No code change expected.
4. **Preserve the consumer contract**: the `role-based-step-routing` spec body is NOT modified.
   The enforcement mechanism changes; the observable behaviour (a Vergunningverlener sees
   their steps; a Behandelaar does not) is preserved or improved (now enforced server-side).
5. **Tests**: verify that step objects filtered by OR's RBAC at the API level match the set
   previously produced by client-side role filtering.

## Capabilities

### New Capabilities

- `role-routing-via-or-rbac`: Step and transition routing decisions are enforced server-side
  via OR's RBAC stack. `GET /api/objects/{register}/workflowStep` returns only steps the
  requesting user is authorized to access per the schema's `authorization` block.

### Modified Capabilities

- `role-based-step-routing` (existing spec) — no body changes. The underlying enforcement
  mechanism changes from client-side filtering to OR server-side RBAC, but all observable
  requirements remain valid.

## Affected Projects

- [x] Project: `procest` — all implementation work is in this repo
- Reference: `hydra/openspec/changes/consume-or-rbac-fleet-wide/` (umbrella policy)
- Reference: `openregister/openspec/specs/rbac-scopes/spec.md` (OR RBAC contract)
- Reference: `openregister/openspec/specs/auth-system/spec.md` (OR auth contract)

## Scope

### In Scope

- Adding `ncGroupId` property to the `roleType` schema in `procest_register.json`
- Adding `authorization` blocks to `workflowStep` and `workflowTemplate` schemas
- Verifying `KpiCacheInvalidationListener` compliance and documenting it
- Tests verifying that OR RBAC correctly restricts step access by NC group membership
- Admin UI: surfacing `ncGroupId` as a configurable field on roleType objects in admin settings

### Out of Scope

- Modifying the `role-based-step-routing` spec body (constraint from umbrella)
- Converting stored `assigneeRole` / `allowedRoles` UUID values in existing workflow
  definitions (those remain UUID references; only the enforcement layer changes)
- Procest's parafering role-gating — addressed by `consume-or-approval-workflow-fleet-wide`
- Procest's `roles-decisions` domain (role assignment as participation record) — this is
  correct OR consumer usage, not a parallel RBAC scheme
- Modifying OR's `rbac-scopes` or `auth-system` specs

## Success Criteria

- `openspec validate --strict migrate-role-routing-to-or-rbac` exits 0.
- `roleType` schema in `procest_register.json` includes an `ncGroupId` property.
- `workflowStep` schema includes an `authorization` block referencing NC group IDs resolved
  from the step's `assigneeRole` `roleType` object.
- `GET /api/objects/{register}/workflowStep` returns HTTP 403 / empty list for a user whose
  NC group is not in the step's resolved authorization set.
- `composer check:strict` passes.
