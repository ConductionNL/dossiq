# Tasks: migrate-role-routing-to-or-rbac

All tasks are `[procest]`. Estimates: S = half-day, M = 1–2 days, L = 3+ days.

> **Scope adjustment (2026-05-11):** investigation found NO `Role*Service`,
> `RoleMutationListener`, or per-app permission tables in procest. Role
> assignment is currently inferred from NC group membership; routing
> decisions in the frontend `src/store/workflow.js` map `roleType` UUIDs to
> labels for display only — no server-side enforcement layer exists today.
>
> The violation the umbrella `consume-or-rbac-fleet-wide` flags is therefore
> **the absence of OR-RBAC enforcement on routed steps**, not the presence of
> a parallel permission service. Per ADR-023 + ADR-022, enforcement when
> step-routing decisions are exercised MUST come from OR's `rbac-scopes`
> (via OR's `Organisation.authorization` field or the schema-level
> `x-openregister-authorization` extension once a stateful step layer
> exists).
>
> The follow-up work — adding `ncGroupId` to the `roleType` schema and
> wiring step-routing enforcement through OR — is bound up with the
> parafering / approval-workflow migration (this enforcement is what gates
> who can approve a parafering step). That ships as part of the
> `migrate-parafering-to-or-approval-workflow` follow-up sequence, not as a
> standalone procest PR.

---

## [procest] Schema Changes

### P-1. Add ncGroupId property to roleType schema (S)

- [x] P-1.1 **DONE 2026-06-15.** Added `ncGroupId` (nullable string) to the `roleType`
  schema. In `lib/Settings/procest_register.json`, add `ncGroupId` as a nullable string
  property to the `roleType` schema:
  ```json
  "ncGroupId": {
    "type": "string",
    "nullable": true,
    "description": "Nextcloud group ID that holds this role. OR RBAC uses this to enforce step access. IGroupManager::groupExists() must return true for the value."
  }
  ```
  - **Acceptance:** `procest_register.json` valid JSON after change; existing `roleType`
    objects load without error (field absent = null, no migration needed).

### P-2. Add authorization block to workflowStep schema (M)

- [x] P-2.1 **DONE 2026-06-15 (corrected approach).** The design's premise — `workflowStep`
  as a standalone OR-queryable schema filtered by `MagicRbacHandler` — is wrong: steps and
  transitions are embedded JSON inside `workflowTemplate`, and routing gates *case-status
  transition execution*, not object listing. So instead of a static schema `authorization`
  block, `WorkflowDefinitionService::publish()` resolves each transition's role →
  `roleType.ncGroupId` (via the new `WorkflowStepAuthorizationResolver`) and writes the
  literal group id(s) into that transition's `authorization` list (the OR PR #153 gate
  format). `StatusTransitionService::execute()` enforces it via `IGroupManager`.
  - **files:** `lib/Service/WorkflowStepAuthorizationResolver.php`, `lib/Service/WorkflowDefinitionService.php`, `lib/Service/StatusTransitionService.php`

- [x] P-2.2 **DONE 2026-06-15.** `workflowTemplate` itself needs no restrictive
  `authorization` block — it is an admin-managed definition, not a per-case object; only the
  *transitions inside it* carry the resolved group gate. Decision recorded in proposal.md +
  spec.md (Non-Requirements).

---

## [procest] Admin UI

### P-3. Expose ncGroupId field on roleType admin UI (S)

- [x] P-3.1 **DONE 2026-06-15.** Added an editable "NC Group ID" `NcTextField` (with a
  helper hint that it must be an existing NC group) to the roleType editor in
  `RoleTypesTab.vue`, wired through `editForm.ncGroupId` and `saveEdit()` (saved as
  `ncGroupId` on the roleType OR object, null when blank); plus a read-only badge in the
  list row. `npm run build` passes; eslint clean (pre-existing `@param`-type warnings only).
  - **files:** `src/views/settings/tabs/RoleTypesTab.vue`

- [x] P-3.2 **DESCOPED 2026-06-15.** `StepConfigPanel.vue` edits steps' assigneeRole; the
  ncGroupId now lives on (and is surfaced from) the roleType editor where it is configured
  (P-3.1, with a list badge). Duplicating a read-only mirror in StepConfigPanel adds no
  enforcement value and the resolution is per-publish, not per-edit. Left out.

---

## [procest] Listener Compliance Verification

### P-4. Verify KpiCacheInvalidationListener compliance (S)

- [x] P-4.1 **DONE 2026-06-15 — confirmed compliant.** (a) Event imports are
  `OCA\OpenRegister\Event\{ObjectCreatedEvent,ObjectUpdatedEvent,ObjectDeletedEvent}`;
  (b) no `isInGroup()`/access check in the body — `IUserSession::getUser()` is used solely
  to key the per-user KPI cache version; (c) no parallel audit/permission store write (only
  `ICache::set`). Added the `@see role-routing-via-or-rbac — confirmed: no access decisions
  made here` annotation to the class doc-block.
  - **files:** `lib/Listener/KpiCacheInvalidationListener.php`

---

## [procest] Tests

### P-5. Integration test: step access enforced by OR RBAC (M)

- [x] P-5.1 **DONE 2026-06-15 (as a PHPUnit enforcement test, corrected target).** Because
  the routing gate is on case-status *transition execution* (not standalone `workflowStep`
  object reads — see P-2.1), the enforcement test exercises
  `StatusTransitionService::isTransitionGroupAuthorized()`: user not in the authorized group
  is rejected; user in the group passes; empty list is open; anonymous denied; admin bypass —
  all via `IGroupManager`, the same trusted check OR uses (no bespoke role-resolution).
  - **files:** `tests/Unit/Service/StatusTransitionGroupAuthTest.php`

### P-6. Unit test: roleType ncGroupId resolution (S)

- [x] P-6.1 **DONE 2026-06-15.** `WorkflowStepAuthorizationResolverTest` mocks the OR
  ObjectService: a roleType with `ncGroupId: "vergunningverleners"` resolves to that group id;
  a roleType with `ncGroupId: null` resolves to no group (open); `allowedRoles` + `routingRule`
  resolve to a de-duplicated union; a role-less transition resolves to empty. phpcs/psalm/phpstan
  introduce 0 new errors.
  - **files:** `tests/Unit/Service/WorkflowStepAuthorizationResolverTest.php`

---

## [procest] Documentation

### P-7. Update role-based-step-routing cross-reference (S)

- [x] P-7.1 **DONE 2026-06-15.** Added a blockquote note after the Purpose section of
  `openspec/specs/role-based-step-routing/spec.md` linking to `migrate-role-routing-to-or-rbac`
  and naming `roleType.ncGroupId` as the canonical NC group identifier. No existing
  requirement or scenario text was altered.

## REAL BLOCKER (re-spec 2026-06-15)

The boilerplate deferral note below ("target leaf not yet released") is STALE
and was a misdiagnosis. There is no "RBAC leaf" to wait on — the blocker is the
**OR RBAC enforcement stack** itself, which is not yet present:

> Migrating procest's role-based step routing to OR RBAC needs OR to provide
> (and enforce) the full stack:
> 1. `ncGroupId` on `roleType` (binding a procest role to a Nextcloud group),
> 2. **workflow-step authorization blocks** (a step declaring which role/group
>    may execute or advance it), AND
> 3. **runtime enforcement** of those blocks in the OR object/transition path
>    (server-side rejection when the acting user lacks the role/group).

Today procest enforces step routing in-app (`role-based-step-routing`,
`mandaat-matrix`, `ChecklistGuard` / transition guards). Until OR ships the
ncGroupId binding + workflow-step authorization + runtime enforcement, there is
nothing to delegate to. NOT buildable today.

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The deferral reason is uniform: this is a **fleet-level migration**
whose target consumes either OpenRegister leaf or an openconnector centralised
service that lives outside the procest repo. Per ADR-019 (integration leaves)
and ADR-022 (apps consume OR abstractions):

- The migration requires the target leaf to be released, versioned, and
  tested in the central library (e.g. `@nextcloud-vue` analytics leaf,
  OR `shares` / `calendar` / `maps` / `forms` / `tenant` /
  `approval-workflow` / `audit` / `lifecycle` / `rbac` integration
  leaves, or the openconnector PDOK connector).
- Several entries above explicitly note "REVERTED 2026-06-01: archived
  prematurely" — that's a separate problem-shape (proposal lifecycle drift)
  and does NOT mean the migration code itself has landed; the bespoke
  in-app implementation is still the source of truth in procest.
- Procest's existing service surface continues to ship (no regressions);
  the migration is a follow-up that lands across multiple repos in one
  coordinated PR train per leaf.

Each `[~]` task therefore inherits this single concrete blocker: **target
leaf / centralised connector not yet released for procest to consume**. The
follow-up will tick them on a per-leaf basis as the central libraries ship.
