---
status: done
---

## Purpose

@e2e exclude Role-based step routing is V1; role-filtered task generation is backend logic covered by PHPUnit.

> Enforcement mechanism: see `migrate-role-routing-to-or-rbac` — step/transition
> access is enforced on the OpenRegister RBAC group model using `roleType.ncGroupId`
> as the canonical NC group identifier. Roles are resolved to literal group ids at
> workflow-publish time and frozen onto each transition's `authorization` list; the
> requirements below (observable routing behaviour) are unchanged.

## Requirements

### Requirement: Role-Based Step Visibility

The system SHALL filter workflow steps and their resulting tasks based on the user's role on the case. Steps configured with an `assigneeRole` SHALL only appear in the task list of users who hold that role on the case.

**Feature tier**: V1

#### Scenario: Step restricted to specific role

- **WHEN** step "Inhoudelijke beoordeling" is configured with `assigneeRole: "Vergunningverlener"`
- **AND** user "jan" has role "Behandelaar" on case "ZK-2024-001"
- **AND** user "piet" has role "Vergunningverlener" on case "ZK-2024-001"
- **THEN** the task for "Inhoudelijke beoordeling" SHALL appear in piet's task list
- **AND** the task SHALL NOT appear in jan's task list

#### Scenario: Step with no role restriction

- **WHEN** step "Checklist invullen" has no `assigneeRole` configured
- **THEN** the resulting task SHALL appear in the task list of ALL users who have any role on the case

### Requirement: Role-Based Transition Access

The system SHALL restrict status transitions to users who hold one of the allowed roles. Transitions with `allowedRoles` defined SHALL only be visible and executable by users who hold at least one of those roles on the case.

**Feature tier**: V1

#### Scenario: Transition restricted to manager role

- **WHEN** transition "Goedkeuren" has `allowedRoles: ["Afdelingshoofd"]`
- **AND** the current user has role "Behandelaar" on the case
- **THEN** the "Goedkeuren" button SHALL NOT be displayed

#### Scenario: Transition with multiple allowed roles

- **WHEN** transition "Terugsturen" has `allowedRoles: ["Behandelaar", "Afdelingshoofd"]`
- **AND** the current user has role "Behandelaar"
- **THEN** the "Terugsturen" button SHALL be displayed and functional

#### Scenario: Transition with no role restriction

- **WHEN** transition "Annuleren" has no `allowedRoles` configured
- **THEN** the transition SHALL be available to any user who has any role on the case

### Requirement: Workflow Inheritance for Role Configuration

The system SHALL support workflow template inheritance where child zaaktypen inherit the parent's workflow and can override specific step role assignments.

**Feature tier**: Enterprise

#### Scenario: Child zaaktype inherits parent workflow

- **WHEN** zaaktype "Reguliere vergunning" extends parent "Omgevingsvergunning"
- **AND** the parent has a workflow with 5 steps
- **THEN** the child SHALL inherit all 5 steps with their role assignments

#### Scenario: Child overrides step role

- **WHEN** the child zaaktype overrides step "Inhoudelijke beoordeling" to use role "Senior Vergunningverlener" instead of "Vergunningverlener"
- **THEN** only the child's override SHALL apply for cases of the child type
- **AND** the parent's original role assignment SHALL remain unchanged

### Requirement: A pool member carries a weight the strategies read (REQ-RTP-01)

A membership of a routing pool SHALL carry a weight, defaulting to one.
Round robin and least loaded SHALL divide work in proportion to the weights.
A pool whose weights are all one SHALL be routed exactly as it is today.

#### Scenario: A part-time member gets a smaller share
@e2e tests/e2e/routing-by-weight-position-and-area.spec.ts

- **GIVEN** a pool of one member at weight 1 and one at weight 0.4
- **WHEN** fourteen cases are routed by round robin
- **THEN** the first member SHALL receive ten and the second four

#### Scenario: An unweighted pool is unchanged
@e2e exclude unit, behaviour parity; WeightedRoundRobinTest

- **GIVEN** a pool whose members all carry weight 1
- **WHEN** cases are routed by either strategy
- **THEN** the result SHALL be identical to the result before this change

#### Scenario: Least loaded reads the weight as capacity
@e2e exclude unit; WeightedLeastLoadedTest

- **GIVEN** a member at weight 2 holding six cases and a member at weight 1 holding four
- **WHEN** the next case is routed by least loaded
- **THEN** it SHALL go to the member at weight 2

### Requirement: A rule may target a position inside a team (REQ-RTP-02)

A routing rule SHALL be able to name a position within a named team, such as
the senior handler of one team, resolved from the organisation. Naming a
position SHALL NOT require an organisation-wide role for it.

#### Scenario: The senior of one team, not of all teams
@e2e tests/e2e/routing-by-weight-position-and-area.spec.ts

- **GIVEN** two teams, each with a senior handler
- **WHEN** a case is routed to the senior handler of team zuid
- **THEN** it SHALL reach that person
- **AND** the senior handler of the other team SHALL not be a candidate

### Requirement: Work not taken up returns to the pool (REQ-RTP-03)

> Built on OpenRegister's timer core (`FlowTimerService`), the same seam
> the term engine uses, so dossiq runs no scheduler of its own (ADR-022, D-4).
> Accepting is the assignee's first status move or first edit of the case;
> there is no accept button (decision 164).

A pool SHALL be able to declare a window within which an assignment must be
accepted or started. When the window passes, the work SHALL return to the
pool and be routed on by the same strategy. The return SHALL record who held
it, why it came back and who holds it now. Accepting SHALL be the first status move or the
first edit of the case by the person it was routed to; a save by anybody
else, or a save that only moves the routing, SHALL NOT count as accepting.
When the pool has nobody else, the case SHALL stay with its holder and the
record SHALL say so.

#### Scenario: An unaccepted case goes back
@e2e exclude time-dependent; unit over the timer-fired listener, TakeBackTest

- **GIVEN** a case routed to a member of a pool with a two-day window
- **WHEN** two working days pass without acceptance
- **THEN** the case SHALL return to the pool and be routed to another member
- **AND** the case SHALL record the first member, the reason and the new holder

#### Scenario: Accepting cancels the window
@e2e tests/e2e/routing-by-weight-position-and-area.spec.ts

- **GIVEN** the same case
- **WHEN** the member accepts it the next morning
- **THEN** it SHALL stay with them
- **AND** no take-back SHALL be recorded

#### Scenario: Somebody else's edit is not accepting
@e2e exclude needs a second signed-in user beside the routed one; unit over the save listener, RoutedCaseAcceptanceListenerTest

- **GIVEN** a case routed to a member with a window
- **WHEN** a colleague edits the case, or the router writes the routing
- **THEN** the case SHALL still be unaccepted
- **AND** the window SHALL still be armed

#### Scenario: A pool with nobody else keeps the case
@e2e exclude time-dependent; unit over the router, TakeBackTest

- **GIVEN** a case routed to the only member of its pool
- **WHEN** the window passes without acceptance
- **THEN** the case SHALL stay with that member
- **AND** the case SHALL record that nobody else was in the pool

### Requirement: The case holds the area it is in, and routing reads it (REQ-RTP-04)

Setting or changing a case's address SHALL resolve its district,
neighbourhood and area from the administered boundaries, and those values
SHALL be held on the case. A routing rule SHALL be able to read them, and
`districtTeam` SHALL be resolved from them. The boundary set SHALL carry its
source and the date it was administered.

#### Scenario: The area team gets the case
@e2e tests/e2e/routing-by-weight-position-and-area.spec.ts

- **GIVEN** a boundary set in which an address falls in wijk Zuid
- **WHEN** a case is created at that address
- **THEN** the case SHALL hold that wijk
- **AND** the case SHALL route to the team declared for it

#### Scenario: Changing the address re-resolves the area
@e2e tests/e2e/routing-by-weight-position-and-area.spec.ts

- **GIVEN** a case in wijk Zuid
- **WHEN** its address is corrected to an address in wijk Noord
- **THEN** the held area SHALL be Noord

#### Scenario: An address outside every boundary uses the fallback
@e2e exclude unit; CaseAreaResolutionTest

- **GIVEN** an address that falls in no administered boundary
- **WHEN** the case is routed
- **THEN** it SHALL route by the fallback the case type declares
- **AND** the case SHALL say that the fallback was used

#### Scenario: The area can be explained
@e2e exclude unit; CaseAreaResolutionTest

- **GIVEN** a case whose area was resolved last year
- **WHEN** the resolution is read
- **THEN** it SHALL name the boundary set and the date it was administered
