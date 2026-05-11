# Proposal: role-based-step-routing

## Summary
Generalise role-based routing into a reusable rule engine that any workflow step or status transition can use to compute its assignee set. Today Procest only resolves "who should act" for B&W parafering (via `ParafeerRouteService`). Cases, tasks and transitions resolve roles ad-hoc in controllers/UI filters, which prevents consistent delegation, out-of-office handling and workload balancing.

## Why
Today only the B&W parafering workflow has a real routing engine (`ParafeerRouteService`). Cases, tasks and status transitions resolve "who can act" with ad-hoc UUID comparisons scattered across controllers and Vue filters. This blocks out-of-office handover, "namens" delegation and any workload-aware assignment — features that the bezwaar/beroep deadlines and 18 NL/BE tenders all require. One shared resolver, used by every workflow step and every status transition, removes the duplication and unlocks the missing features.

## Motivation
- Tender coverage: `assigneeRole` and `allowedRoles` are advertised in 18 NL/BE tenders as "automatic task routing" / "geautomatiseerd routeren naar rol".
- Internal consistency: `WorkflowStep.assigneeRole`, `StatusTransition.allowedRoles`, parafeerStap.actor, and ad-hoc task assignment each apply their own resolution logic — duplicated and divergent.
- Operational need: out-of-office handover, "namens" delegation, and workload-aware routing are required for the bezwaar/beroep workflows whose hearing-window deadlines cannot drift when a case handler is absent.

## What Changes
- New `routingRule` field on `workflowStep` and `statusTransition` (procest_register.json).
- New `delegate`, `delegateFrom`, `delegateUntil` fields on `role`.
- New `RoleResolverService` + `StrategyRegistry` with five built-in strategies.
- New `POST /api/cases/{id}/reroute` endpoint.
- Migration: `ParafeerRouteService::activateStep` calls the resolver instead of inline UUID lookup.
- Admin UI: routing rule editor inside the workflow step form.
- APCu cache + invalidation on role mutations.

## Affected Projects
- [x] Project: `procest` — RoleResolverService, controller hook, admin UI for rule config, ParafeerRouteService back-fill

## Scope

### In Scope
- Single `RoleResolverService` with a pluggable Strategy registry (single-role, OR-set, hierarchical, round-robin, least-loaded).
- Routing rule fields on `workflowStep` and `statusTransition` (resolver strategy + parameters).
- Out-of-office / delegation lookup against the `role` schema (delegate participant + window).
- Re-route controller endpoint so admins can recompute assignees after a delegation change.
- Migration: ParafeerRouteService consumes the new resolver instead of its private logic.

### Out of Scope
- Skill-based routing (different domain — owned by `pipelinq`).
- External LDAP/AD group expansion (V2; resolver returns participant refs only).
- Push notifications to delegate; covered by existing notification service.

## Dependencies
- `workflow-definition-model` — provides `WorkflowStep.assigneeRole` and `StatusTransition.allowedRoles`.
- `status-transition-engine` — calls the resolver during guard evaluation.
