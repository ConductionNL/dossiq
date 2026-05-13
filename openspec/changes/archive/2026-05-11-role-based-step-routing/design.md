# Design: role-based-step-routing

## Architecture

The routing engine is a single backend service, `RoleResolverService`, that takes a `RoutingRule` plus a `Case` and returns an ordered set of `Participant` references. It is invoked by:

1. The task list builder when assembling "my work" for a user.
2. The status-transition engine when evaluating the `roleGuard` on a transition.
3. The parafeerroute step activator (replaces inline logic in `ParafeerRouteService::activateStep`).
4. A new admin re-route endpoint, used after delegation changes or manual override.

## Routing Strategies

Each strategy is a class implementing `RoutingStrategyInterface::resolve(RoutingRule $rule, Case $case): array`. Selected via the rule's `strategy` field; registered in a `StrategyRegistry` keyed by strategy name.

| Strategy | Behaviour | Use case |
|----------|-----------|----------|
| `single-role` | Returns all participants currently bound to the named `roleType` on the case. | Default for `WorkflowStep.assigneeRole`. |
| `or-set` | Union over a set of `roleType` UUIDs. | Steps that any of {Behandelaar, Senior} may perform. |
| `hierarchical` | Tries roles in priority order; returns the first non-empty set. | Falls back to "Afdelingshoofd" when no "Senior" is assigned. |
| `round-robin` | Rotates across participants of the role using a per-caseType cursor stored in `appconfig`. | Even distribution across a pool of inspectors. |
| `least-loaded` | Picks the participant whose open task count is lowest. | Workload-aware routing on high-volume zaaktypes. |

`round-robin` and `least-loaded` MAY return a single participant; `single-role`, `or-set`, `hierarchical` MAY return many (all matching). The caller decides whether to assign all or pick one.

## Data Model

A `RoutingRule` is an embedded object on `WorkflowStep` and `StatusTransition`:

```
{
  "strategy": "single-role|or-set|hierarchical|round-robin|least-loaded",
  "roleTypes": ["uuid", ...],     // OR-set, hierarchical (in priority order)
  "roleType":  "uuid",            // single-role, round-robin, least-loaded
  "fallback":  "uuid"             // optional roleType when primary set is empty
}
```

The existing `assigneeRole`/`allowedRoles` fields remain valid: at read-time they are normalised to `{strategy: "single-role", roleType: <uuid>}` and `{strategy: "or-set", roleTypes: [<uuid>...]}` respectively.

## Delegation & Out-of-Office

The `role` schema gains optional `delegate` (participant) and `delegateFrom`/`delegateUntil` (date) fields. When `RoleResolverService` materialises a participant set, every entry whose `delegateFrom <= now <= delegateUntil` is replaced by its delegate. The original assignment is preserved in `audit.original` for traceability.

## Integration With Other Engines

- `status-transition-engine` — calls `RoleResolverService::canExecute($transition->rule, $case, $userId)` for role-guard evaluation. Reuses the resolver instead of comparing UUIDs directly.
- `ParafeerRouteService` — T07 replaces its inline `findActorForStep()` with a `RoleResolverService` call; the parafeerroute step keeps its semantics but gets delegation + workload features for free.
- `skill-routing` (pipelinq) — explicitly out of scope; that engine routes on `skill` taxonomy, not `roleType`. The two engines may compose in V2 but do not share code today.

## Failure Modes

- No matching participants: resolver returns an empty array; callers MUST present the task to the case admin and log `RoleRoutingEmpty` with rule + caseId.
- Cyclic delegation (A → B → A): detector in resolver, breaks after one hop and logs `RoleRoutingDelegationCycle`.
- Strategy not registered: resolver throws `RoutingStrategyMissingException`; the admin UI prevents saving rules with unknown strategies.

## Performance

Resolver is hot path (called once per task list render). Cache per `(ruleHash, caseId)` for 60 s in APCu. Invalidate on `role` or `case.assignedRoles` mutation via `\OCP\IEventDispatcher` listener.
