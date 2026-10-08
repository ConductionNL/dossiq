# Tasks: routing-by-weight-position-and-area

Tier: V1. Kind: code. Size M. Rows 3.25 and 11.35.

- [x] 1.1 `lib/Settings/dossiq_register.json`: `role.weight`, defaulting to
  one, on the MEMBERSHIP rather than on the person (D-1), plus `role.team`
  so a position inside a team can be named.
- [x] 1.2 `RoundRobinStrategy` and `LeastLoadedStrategy` read the weight
  through `lib/Service/Routing/PoolMembership.php`. An all-ones pool returns
  its participants UNCHANGED rather than an equivalent sequence, which is
  what makes the parity a fact (D-2). Round robin interleaves the shares;
  least loaded compares load per unit of weight, which is the comparison a
  raw count gets backwards.
  - `tests/Unit/Service/Routing/WeightedRoundRobinTest.php`
  - `tests/Unit/Service/Routing/WeightedLeastLoadedTest.php`
- [x] 2.1 A rule naming `team` narrows the pool to that team, so the senior
  handler of team zuid is a target without an organisation-wide role for
  every senior of every team (D-3). The round-robin cursor is keyed by the
  team too: two rules over one roleType in two teams are two rotations.
  - `tests/Unit/Service/Routing/PositionTargetTest.php`
- [ ] 3.1 A take-back window on the pool. NOT BUILT, and the spec delta says
  so where the requirement stands: the window must be an armed timer rather
  than a sweep (D-4), and timers are openregister's `flow-business-timers`.
  dossiq arming its own would be the second scheduler ADR-022 exists to
  prevent. It moves when the case-level timer seam is agreed with the
  openregister lane.
- [ ] 3.2 The take-back record, which follows 3.1 and is deferred with it.
- [x] 4.1 `lib/Service/Cases/CaseAreaResolver.php`: the wijk and the buurt
  from the address, resolved ONCE and held on the case with the source and
  the moment it was read (D-5, D-7). An address that placed in nothing is
  still stamped, because a case that could not be placed and a case nobody
  looked at are different facts and only the timestamp separates them.
  - `tests/Unit/Service/CaseAreaResolutionTest.php`
- [x] 4.2 The boundary set is named on every resolution (`areaSource`), read
  through `PdokService` as the proposal's own interim. The outbound
  connection is integriq's `pdok-geo-boundaries` (ADR-019) and does not
  exist yet; when it does, the field says which set placed each case.
- [x] 4.3 `lib/Service/Routing/AreaRouting.php`: the predicate over the held
  values, and the reader `districtTeam` never had. A value on the case wins
  over the rule's map, because it is a decision somebody made about THIS
  case.
  - `tests/Unit/Service/Routing/AreaPredicateTest.php`
- [x] 4.4 A case outside every boundary routes by the case type's
  `areaFallbackRoleType` and carries `areaFallbackUsed`, so it can be found
  and the boundary set can be fixed (D-6).
- [x] 5.1 Dutch and English strings for the weight, the team, the area
  fields and the fallback, rebuilt into the browser catalogues.
- [x] 5.2 `tests/e2e/routing-by-weight-position-and-area.spec.ts`: a
  weighted division counted over a full cycle, a case routed to a team
  position with the other team's senior never a candidate, an address routed
  to its area team, an address corrected into another wijk, and one routed
  by the fallback and flagged as such;
  `openspec validate routing-by-weight-position-and-area --strict`.

## What a reader should not assume from a tick

The strategies read the pool; nothing in this change CHANGES where the pool
comes from. The role rows bound to a case are still the pool, and `team`
arrives on them from the organisation rather than from a list dossiq keeps.

`CaseAreaResolver` is the resolution and its rules; the write-through that
calls it when a case's address is set lives with `case-location`, whose
listener owns the address, and is not wired here. Until it is, the area
fields are filled by whoever writes them and the predicate reads what it
finds: a case with no area routes by the fallback and says so, which is the
same path an unresolvable address takes.
