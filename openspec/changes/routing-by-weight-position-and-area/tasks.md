# Tasks: routing-by-weight-position-and-area

Tier: V1. Kind: code. Size M. Rows 3.25 and 11.35.

- [ ] 1.1 `lib/Settings/dossiq_register.json`: a weight per pool membership,
  defaulting to one (D-1).
  - `@spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md`
- [ ] 1.2 `lib/Service/Routing/Strategy/RoundRobinStrategy.php` and
  `LeastLoadedStrategy.php` read the weight; an all-ones pool behaves exactly
  as it does today (D-2).
  - `tests/unit/Service/Routing/WeightedRoundRobinTest.php`
  - `tests/unit/Service/Routing/WeightedLeastLoadedTest.php`
- [ ] 2.1 A position inside a team as a routing target, resolved from the
  organisation rather than from a new role list (D-3).
  - `tests/unit/Service/Routing/PositionTargetTest.php`
- [ ] 3.1 A take-back window declared on the pool, armed as an engine timer
  on assignment and cancelled on acceptance (D-4).
  - `tests/unit/Service/Routing/TakeBackTest.php`
- [ ] 3.2 The take-back records who held it, why it returned and who has it
  now, and routes on by the same strategy (D-4).
- [ ] 4.1 Resolve the case's district, neighbourhood and area when the
  address is set or changed, and hold them on the case (D-5).
  - `tests/unit/Service/CaseAreaResolutionTest.php`
- [ ] 4.2 Administer the boundary set with its source and its date; read it
  through `PdokService` until the integriq connection exists (D-7).
- [ ] 4.3 A routing predicate over the held values, and the reader
  `districtTeam` never had.
  - `tests/unit/Service/Routing/AreaPredicateTest.php`
- [ ] 4.4 A case outside every boundary routes by the declared fallback and
  says so (D-6).
- [ ] 5.1 Dutch and English strings for the weight, the position, the
  take-back reason and the area fields.
- [ ] 5.2 `tests/e2e/routing-by-weight-position-and-area.spec.ts`: a weighted
  division over a pool, a case routed to a team position, an unaccepted
  assignment taken back, an address routed to its area team and one routed by
  the fallback;
  `openspec validate routing-by-weight-position-and-area --type change --strict`.
