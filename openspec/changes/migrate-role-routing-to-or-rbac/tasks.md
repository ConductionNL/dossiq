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

- [ ] P-1.1 In `lib/Settings/procest_register.json`, add `ncGroupId` as a nullable string
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

- [ ] P-2.1 Add an `authorization` block to the `workflowStep` schema in `procest_register.json`.
  Use the register's OR RBAC structure to restrict read/update access to users in the group
  resolved from `assigneeRole` → `roleType.ncGroupId`. If OR's named-role expansion at
  register level is not yet available in the deployed `rbac-scopes` implementation, use a
  static group representing "all procest users" as an interim (documenting the limitation
  in a comment in the JSON). File a tracking issue for the dynamic expansion follow-on.
  - **Acceptance:** `workflowStep` schema has an `authorization` block; `GET /api/objects/
    {register}/workflowStep` goes through MagicRbacHandler for the procest register; no
    runtime errors on the endpoint.

- [ ] P-2.2 Verify the `workflowTemplate` schema does NOT need a restrictive `authorization`
  block for the step-routing scenario (templates are admin-managed definitions, not per-case
  objects). Document the decision in design.md.
  - **Acceptance:** Design.md updated with the decision; `workflowTemplate` schema unchanged
    if admin-only access is sufficient.

---

## [procest] Admin UI

### P-3. Expose ncGroupId field on roleType admin UI (S)

- [ ] P-3.1 In the case type admin settings panel, add a text input field "NC Group ID"
  for each roleType configuration. The field maps to `ncGroupId` on the roleType OR object.
  Validate the field client-side: if the user enters a value, display a hint "this must be
  an existing Nextcloud group ID."
  - **Acceptance:** Admin can view and edit `ncGroupId` on a roleType in the admin settings
    UI; save correctly updates the OR object.

- [ ] P-3.2 In `StepConfigPanel.vue`, add a read-only display field "NC Group:" showing
  the `ncGroupId` of the step's resolved roleType (if set). Display "— (not mapped)" if
  `ncGroupId` is null.
  - **Acceptance:** StepConfigPanel shows the resolved NC group ID for steps with a configured
    assigneeRole; displays "— (not mapped)" for steps without one.

---

## [procest] Listener Compliance Verification

### P-4. Verify KpiCacheInvalidationListener compliance (S)

- [ ] P-4.1 Read `lib/Listener/KpiCacheInvalidationListener.php` and confirm:
  (a) All event `use` imports are from `OCA\OpenRegister\Event\*` namespace.
  (b) No `$groupManager->isInGroup()` or equivalent access-control call in the body.
  (c) No write to any parallel audit or permission store.
  Document findings in this task's acceptance note.
  - **Acceptance:** Listener confirmed compliant (or corrective sub-task filed if not).
    A comment is added to the listener class doc-block referencing this spec:
    `@see role-routing-via-or-rbac — confirmed: no access decisions made here`.

---

## [procest] Tests

### P-5. Integration test: step access enforced by OR RBAC (M)

- [ ] P-5.1 Write an integration test (Newman or PHPUnit integration) that:
  (a) Creates two NC users: `jan` (not in `vergunningverleners`) and `piet` (in
      `vergunningverleners`).
  (b) Creates a roleType with `ncGroupId: "vergunningverleners"`.
  (c) Creates a `workflowStep` with `assigneeRole` pointing to that roleType.
  (d) Calls `GET /api/objects/{register}/workflowStep` authenticated as `jan`; asserts the
      step is absent.
  (e) Calls the same endpoint authenticated as `piet`; asserts the step is present.
  (f) Asserts no `isInGroup()` call was made by procest code during (d) and (e) (verify via
      absence of any such call in the controller/service, not runtime tracing).
  - **Acceptance:** Test passes against a running NC dev instance with procest + OR installed.
    No client-side filtering is applied before the assertion in (d) and (e).

### P-6. Unit test: roleType ncGroupId resolution (S)

- [ ] P-6.1 Write a PHPUnit unit test for the routing resolution logic:
  mock an OR `ObjectService` response returning a roleType with `ncGroupId: "group-a"`;
  assert that the step routing service returns `"group-a"` as the enforcement group.
  Mock a roleType with `ncGroupId: null`; assert the routing service returns null (open access).
  - **Acceptance:** Test passes under `composer check:strict`; zero PHPCS/PHPStan errors.

---

## [procest] Documentation

### P-7. Update role-based-step-routing cross-reference (S)

- [ ] P-7.1 Add a note to `openspec/specs/role-based-step-routing/spec.md` in the `## ADDED
  Requirements` section (or as a standalone comment above) that links to this migration change:
  "Enforcement mechanism: see `migrate-role-routing-to-or-rbac` — step access is enforced
  server-side via OR RBAC (rbac-scopes) using `roleType.ncGroupId` as the NC group identifier."
  Do NOT modify any existing requirement or scenario text.
  - **Acceptance:** `role-based-step-routing/spec.md` references this migration change by
    slug; no existing requirement text is altered.

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
