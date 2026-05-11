# Tasks: role-based-step-routing

## 1. Data Model

### T01: Add routing rule schema fields
- **spec_ref**: `openspec/changes/role-based-step-routing/specs/role-based-step-routing/spec.md#requirement-routing-rule-data-model`
- **files**: `lib/Settings/procest_register.json`
- **acceptance_criteria**:
  - GIVEN a workflowTemplate schema WHEN inspected THEN `workflowStep` and `statusTransition` each accept an optional `routingRule` object with `strategy`, `roleTypes`, `roleType`, `fallback` fields.
  - GIVEN a `role` schema WHEN inspected THEN it has optional `delegate`, `delegateFrom`, `delegateUntil` properties.
  - GIVEN a workflow saved with the legacy `assigneeRole` UUID WHEN read THEN the API normalises it to a `routingRule` with `strategy: "single-role"`.
- [x] Extend `procest_register.json` and a repair step migration.

### T02: RoleResolverService backend
- **spec_ref**: `openspec/changes/role-based-step-routing/specs/role-based-step-routing/spec.md#requirement-role-resolver-service`
- **files**: `lib/Service/RoleResolverService.php`
- **acceptance_criteria**:
  - GIVEN a rule + case WHEN `resolve()` is called THEN it returns an ordered array of participant references.
  - GIVEN a participant with active delegation WHEN resolving THEN the delegate replaces the original and `audit.original` is populated.
  - GIVEN a cyclic delegation chain WHEN resolving THEN the resolver breaks after one hop and logs `RoleRoutingDelegationCycle`.
- [x] Implement service with constructor injection of `ObjectService`, `IUserSession`, `LoggerInterface`, `IAppConfig`.

### T03: Strategy registry
- **spec_ref**: `openspec/changes/role-based-step-routing/specs/role-based-step-routing/spec.md#requirement-strategy-registry`
- **files**: `lib/Service/Routing/StrategyRegistry.php`, `lib/Service/Routing/Strategy/*.php`
- **acceptance_criteria**:
  - GIVEN five strategies registered (single-role, or-set, hierarchical, round-robin, least-loaded) WHEN listed THEN all five names are exposed by `StrategyRegistry::list()`.
  - GIVEN an unknown strategy name WHEN the registry is asked to resolve it THEN it throws `RoutingStrategyMissingException`.
- [x] Implement the five strategies behind `RoutingStrategyInterface`.

### T04: Case.assignedTo updater hook
- **spec_ref**: `openspec/changes/role-based-step-routing/specs/role-based-step-routing/spec.md#requirement-assignee-recomputation`
- **files**: `lib/EventListener/RoleMutationListener.php`
- **acceptance_criteria**:
  - GIVEN a `role` is created, updated, or deleted on a case WHEN the event fires THEN `case.assignedTo` and every open step's assignee set are recomputed.
  - GIVEN APCu cache holds resolver results for that case WHEN the event fires THEN the cache entry is invalidated.
- [x] Wire the listener to `\OCP\IEventDispatcher` for OpenRegister object events on the `role` schema.

### T05: Re-route controller endpoint
- **spec_ref**: `openspec/changes/role-based-step-routing/specs/role-based-step-routing/spec.md#requirement-manual-re-route-endpoint`
- **files**: `lib/Controller/RoutingController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - POST `/api/cases/{id}/reroute` SHALL recompute every open step's assignees for that case and return the affected step ids.
  - GIVEN the requester lacks the `admin` role on the case WHEN called THEN it returns 403.
- [x] Add controller, route, and OCS auth annotations.

### T06: Admin UI for rule configuration
- **spec_ref**: `openspec/changes/role-based-step-routing/specs/role-based-step-routing/spec.md#requirement-admin-rule-configuration-ui`
- **files**: `src/views/admin/CaseTypeWorkflow.vue`, `src/components/routing/RoutingRuleEditor.vue`
- **acceptance_criteria**:
  - GIVEN an admin opens a workflow step WHEN editing the routing rule THEN a dropdown lists the five strategies and reveals strategy-specific fields.
  - GIVEN an admin saves a rule with an unknown strategy WHEN submitted THEN the save is blocked with a "Strategie niet gevonden" error.
- [ ] Build `RoutingRuleEditor.vue` and slot it into the existing workflow step editor.

### T07: Migrate ParafeerRouteService
- **spec_ref**: `openspec/changes/role-based-step-routing/specs/role-based-step-routing/spec.md#requirement-parafeerroute-integration`
- **files**: `lib/Service/ParafeerRouteService.php`
- **acceptance_criteria**:
  - GIVEN a parafeerroute step has an actor of type `role` WHEN the step is activated THEN `RoleResolverService` resolves the participant set (previously inline logic).
  - GIVEN an existing parafeerroute in production data WHEN read after migration THEN behaviour is identical for every step that does not declare a strategy (defaults to `single-role`).
- [x] Replace `findActorForStep()` with a resolver call; keep public API stable.

### V01: Unit tests for resolver and strategies
- **spec_ref**: `openspec/changes/role-based-step-routing/specs/role-based-step-routing/spec.md`
- **files**: `tests/Unit/Service/RoleResolverServiceTest.php`, `tests/Unit/Service/Routing/*Test.php`
- **acceptance_criteria**: Each strategy has at least three tests covering empty set, single match, multi match. Resolver tests cover delegation, cycle break, and cache hit.
- [ ] Achieve ≥85% line coverage on the resolver and strategy classes.

### V02: Integration test for status-transition role guard
- **spec_ref**: `openspec/changes/role-based-step-routing/specs/role-based-step-routing/spec.md#requirement-role-resolver-service`
- **files**: `tests/Integration/StatusTransitionRoleGuardTest.php`
- **acceptance_criteria**: A transition with `allowedRoles` resolves through `RoleResolverService` and respects delegation.
- [ ] Use OpenRegister fixtures, no live DB.

### V03: Newman re-route endpoint test
- **spec_ref**: `openspec/changes/role-based-step-routing/specs/role-based-step-routing/spec.md#requirement-manual-re-route-endpoint`
- **files**: `tests/newman/role-routing.postman_collection.json`
- **acceptance_criteria**: Newman collection creates a case, adds a delegation, calls `/reroute`, asserts the open step assignee is now the delegate.
- [ ] Add to CI Newman matrix.

### V04: Performance benchmark
- **spec_ref**: `openspec/changes/role-based-step-routing/specs/role-based-step-routing/spec.md#requirement-resolver-performance-budget`
- **files**: `tests/Performance/RoleResolverBenchmark.php`
- **acceptance_criteria**: P95 resolution time ≤ 5 ms over 100 calls against a case with five open steps and a populated APCu cache.
- [ ] Run against the dev environment; record baseline in the build log.
