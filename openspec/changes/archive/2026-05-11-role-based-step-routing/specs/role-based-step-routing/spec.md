## ADDED Requirements

### Requirement: Routing Rule Data Model
The system SHALL store routing configuration as a `routingRule` object embedded on each `workflowStep` and `statusTransition`. The rule defines which strategy to apply and the role parameters it needs. Legacy `assigneeRole` (UUID) and `allowedRoles` (UUID array) fields SHALL continue to work and SHALL be normalised on read to a `routingRule` with `strategy: "single-role"` and `strategy: "or-set"` respectively.

**Feature tier**: V1

| Property | Type | Description |
|----------|------|-------------|
| `strategy` | enum | One of `single-role`, `or-set`, `hierarchical`, `round-robin`, `least-loaded` |
| `roleType` | UUID | Role type for `single-role`, `round-robin`, `least-loaded` |
| `roleTypes` | array of UUID | Role types for `or-set` (union) or `hierarchical` (priority order) |
| `fallback` | UUID | Role type to try when primary resolution yields empty |

#### Scenario: Save a workflow step with a routing rule
- **WHEN** an administrator saves a `workflowStep` with `routingRule.strategy = "round-robin"` and `routingRule.roleType = <inspector-uuid>`
- **THEN** the workflowTemplate document SHALL persist the rule unchanged
- **AND** subsequent task activation SHALL resolve assignees via the round-robin strategy

#### Scenario: Read legacy assigneeRole
- **WHEN** the API returns a `workflowStep` that has only the legacy `assigneeRole` field set
- **THEN** the response SHALL include a computed `routingRule` with `strategy: "single-role"` and `roleType: <same uuid>`

#### Scenario: Role schema gains delegation fields
- **WHEN** a `role` object is created or updated with `delegate`, `delegateFrom`, `delegateUntil`
- **THEN** the system SHALL persist the values
- **AND** validate that `delegateFrom <= delegateUntil`

### Requirement: Role Resolver Service
The system SHALL provide a backend `RoleResolverService` that, given a `routingRule` and a `case`, returns an ordered array of participant references representing the current assignee set. The resolver SHALL apply delegation, detect cyclic delegation, and cache results.

**Feature tier**: V1

#### Scenario: Resolve a single-role rule
- **WHEN** `resolve()` is called with `strategy: "single-role"` and `roleType: <behandelaar-uuid>` against case ZK-2024-001 which has two `role` records of that type
- **THEN** the resolver SHALL return both participant references in case-role creation order

#### Scenario: Apply active delegation
- **WHEN** a participant has `delegate: "piet"`, `delegateFrom: yesterday`, `delegateUntil: tomorrow`
- **AND** the resolver runs today
- **THEN** the returned array SHALL contain `piet` in place of the original participant
- **AND** the audit record on the assignment SHALL carry `original: <participant-uuid>`

#### Scenario: Break cyclic delegation
- **WHEN** participant A delegates to B and B delegates back to A
- **AND** the resolver evaluates the rule
- **THEN** the resolver SHALL break after one hop and SHALL log `RoleRoutingDelegationCycle` with both participant UUIDs
- **AND** SHALL return the first-hop participant (B) so the case remains assignable

#### Scenario: Resolver cache hit
- **WHEN** the resolver is called twice in succession for the same `(ruleHash, caseId)` within 60 seconds
- **THEN** the second call SHALL be served from APCu without hitting OpenRegister

### Requirement: Strategy Registry
The system SHALL expose a `StrategyRegistry` that holds the five built-in routing strategies and SHALL reject rules referencing an unregistered strategy.

**Feature tier**: V1

#### Scenario: Built-in strategies are registered
- **WHEN** the registry is queried
- **THEN** the list SHALL contain exactly `single-role`, `or-set`, `hierarchical`, `round-robin`, `least-loaded`

#### Scenario: Unknown strategy is rejected
- **WHEN** a `routingRule` with `strategy: "skill-based"` is submitted to the resolver
- **THEN** the resolver SHALL throw `RoutingStrategyMissingException`
- **AND** the admin UI SHALL block saving such a rule with the error "Strategie niet gevonden"

#### Scenario: Hierarchical fall-through
- **WHEN** a rule has `strategy: "hierarchical"` with `roleTypes: ["Senior", "Behandelaar"]`
- **AND** no participants hold the "Senior" role on the case
- **THEN** the resolver SHALL try "Behandelaar" and return the participants from that role

#### Scenario: Round-robin rotation persists across calls
- **WHEN** the round-robin strategy is asked to resolve the same rule three times in a row for a role with three participants
- **THEN** each call SHALL return a different participant in stable rotation order
- **AND** the cursor SHALL be persisted in `appconfig` under key `routing.rr.<caseTypeUuid>.<roleTypeUuid>`

#### Scenario: Least-loaded picks the lowest task count
- **WHEN** the least-loaded strategy is invoked for a role held by three participants with open-task counts 5, 2, 8
- **THEN** the resolver SHALL return the participant with 2 open tasks

### Requirement: Assignee Recomputation
The system SHALL recompute `case.assignedTo` and every open step's assignee set whenever a `role` record on the case is created, updated, or deleted, and SHALL invalidate the resolver cache for that case.

**Feature tier**: V1

#### Scenario: Adding a role recomputes assignees
- **WHEN** a new `role` of type "Behandelaar" is added to case ZK-2024-007 which has an open step with `routingRule.strategy = "single-role"` and `roleType = Behandelaar`
- **THEN** the open step's assignee set SHALL include the new participant within one event-loop tick
- **AND** the resolver APCu entry for that case SHALL be evicted

#### Scenario: Deleting the last role triggers admin notification
- **WHEN** the only `role` matching an open step's rule is deleted
- **THEN** the resolver SHALL return an empty array
- **AND** a `RoleRoutingEmpty` log line SHALL be emitted with rule + caseId
- **AND** the step SHALL be surfaced to the case admin's "Needs attention" list

### Requirement: Manual Re-route Endpoint
The system SHALL expose `POST /api/cases/{id}/reroute` to recompute the assignee set for every open step on a case, returning the list of affected step ids. The endpoint SHALL require the caller to hold an admin role on the case.

**Feature tier**: V1

#### Scenario: Admin re-routes after delegation change
- **WHEN** an admin calls `POST /api/cases/ZK-2024-001/reroute`
- **AND** the case has two open steps
- **THEN** the response status SHALL be 200
- **AND** the response body SHALL include `affectedSteps` listing both step ids

#### Scenario: Non-admin is forbidden
- **WHEN** a user with only the "Behandelaar" role calls the reroute endpoint
- **THEN** the response status SHALL be 403

### Requirement: Admin Rule Configuration UI
The system SHALL render a routing rule editor inside the workflow step admin form that lets administrators pick a strategy and configure its parameters.

**Feature tier**: V1

#### Scenario: Strategy dropdown reveals parameters
- **WHEN** an admin selects strategy "hierarchical" in the routing rule editor
- **THEN** the form SHALL show an ordered multi-select for `roleTypes`
- **AND** SHALL hide the single `roleType` field

#### Scenario: Save blocked on unknown strategy
- **WHEN** an admin manually submits a payload with `strategy: "made-up"`
- **THEN** the API SHALL return 422 and the UI SHALL show "Strategie niet gevonden"

### Requirement: ParafeerRoute Integration
The system SHALL route every parafeerroute step that targets a role through `RoleResolverService` rather than the legacy inline lookup, preserving behaviour for routes that do not declare a strategy.

**Feature tier**: V1

#### Scenario: Existing parafeerroute behaves identically
- **WHEN** a parafeerroute step has actor type `role` and no strategy declared
- **THEN** `ParafeerRouteService::activateStep` SHALL resolve via `RoleResolverService` with default `strategy: "single-role"`
- **AND** the resulting actor set SHALL equal what the pre-migration code returned

#### Scenario: Parafering inherits delegation
- **WHEN** the role actor on a parafeerstap has an active delegate
- **THEN** the activated parafeertaak SHALL be assigned to the delegate
- **AND** the audit trail SHALL record the original actor

### Requirement: Resolver Performance Budget
The system SHALL resolve routing rules with a P95 latency of 5 ms or less for a case with five open steps when the APCu cache is populated.

**Feature tier**: V1

#### Scenario: Benchmark meets budget
- **WHEN** the benchmark suite calls `RoleResolverService::resolve()` 100 times against a fixture case
- **THEN** the P95 latency SHALL be 5 ms or less
- **AND** the result SHALL be recorded in the build log
