---
kind: code
depends_on: []
---

# Proposal: routing-by-weight-position-and-area

## The rows this closes

**3.25**, area Tasks and phases, rated `partial`: "Work spread over a pool
by round robin, open workload or weight."

Source field, verbatim: `dossiq#2314, published as 3.22`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **3.25** | 3.22 | Work spread over a pool by round robin, open workload or weight | partial | unread | corpus 1.12 |
```

The ledger note, verbatim:

> Routing/Strategy has RoundRobin and LeastLoaded. There is no per-person weight, no way to target a named position inside a team, and no condition that takes work back.

**11.35**, area Configuration, rated `no`: "Routing predicates over where
the case is: district, neighbourhood or area."

Source field, verbatim: `dossiq#2314, published as 11.27`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.35** | 11.27 | Routing predicates over where the case is: district, neighbourhood or area | no | unread |  |
```

The ledger note, verbatim:

> Routing/Strategy holds Hierarchical, LeastLoaded, OrSet, RoundRobin and SingleRole, and PdokService resolves an address without boundaries. districtTeam is declared with no PHP reader, and the wijk and buurt selection is a written spec scenario nothing implements.

## What the competitor evidence is

None for either row. Both are among the 98 rows promoted under decision D1,
and the batch file states: "Every competitor column is `unread`, and none of
them is `no`. ... `no` is a reading of a product somebody opened, and
filling these cells with it would fabricate thirty readings per row."

Row 3.25 cross-references corpus row 1.12, a neighbouring question rather
than a reading of a product on this row.

## Why

Routing decides who gets the work, and dossiq decides it on two facts: turn
and count.

**Turn and count are not fair.** `RoundRobinStrategy` gives everyone the
same number of cases and `LeastLoadedStrategy` gives the next one to
whoever holds fewest. Both assume every handler is available in the same
measure and every case costs the same. A colleague at three days a week and
a colleague who also runs the objection caseload get the same share, and the
share is wrong for both. A weight per member is the one number that makes
the two existing strategies usable.

**A team is not a flat list of roles.** Routing can name a role across the
organisation or set a hierarchy, but not "the senior handler of team zuid".
So a team with two seniors and six handlers is routed as eight
interchangeable people, and the administrator's only way to express the
difference is another role that means the same thing somewhere else.

**Nothing takes work back.** Once a case is routed it stays routed. A case
assigned to someone on leave sits there until a person notices, which is
exactly the case the routing was supposed to prevent.

**Place is not a predicate.** A case has an address.
`PdokService` resolves it and stops at the coordinates. `districtTeam` is
declared in the register and no PHP reads it, and the wijk and buurt
selection is a scenario written in a spec that nothing implements. So the
oldest routing rule in Dutch municipal work, the area team handles the area,
cannot be expressed.

## What changes

- A weight per pool member, read by round robin and by least loaded, so the
  two strategies divide work in proportion to availability rather than by
  head count.
- A position inside a team as a routing target, so a rule can name the
  senior handler of one team without inventing an organisation-wide role.
- A take-back condition: an assignment not accepted or not started inside
  the declared window returns to the pool, with the reason recorded and the
  next member chosen by the same strategy.
- The case's location resolves to a district, a neighbourhood and an area
  from administered boundaries, held on the case so a routing rule and a
  filter can read it.
- A routing predicate over those values, and `districtTeam` gets its reader,
  so the area team rule is configuration rather than a scenario nobody
  implemented.
- A case whose address falls outside every boundary routes by the fallback
  the case type declares and says that it did, rather than failing silently.

## Ownership

dossiq builds the strategies, the weight, the position target, the take-back
and the predicates. Who handles which work is case administration.

Consumed:
- openregister organisation and RBAC (shipped) for the teams and the
  positions inside them, so dossiq holds no second org chart;
- openregister `flow-business-timers` (shipped) for the window after which
  work is taken back, so nothing sweeps;
- openregister `lifecycle-declarative-conditions`, to be specified in
  openregister, for the predicate vocabulary a routing rule is written in;
- the boundary source. dossiq's own `pdok-consumer` resolves the address
  today. The outbound connection belongs in integriq's connection registry
  under ADR-019, as `pdok-geo-boundaries`, to be specified in integriq;
  until it exists dossiq reads the boundaries through `PdokService` as it
  reads addresses now.

## ADRs

- Company ADR-019: the geo source is a declared connection, not a URL in a
  service.
- Company ADR-022: the organisation, the timers and the object queries are
  the platform's.
- Company ADR-031: the weight, the position and the predicate are declared
  configuration, not a strategy class per municipality.
- Company ADR-038 for the requirement ids.

## Size

M. Two additions to strategies that exist, one new predicate source and one
timer.

## The existing spec this extends

`role-based-step-routing`, beside `case-location` and `pdok-consumer`, which
already hold the address resolution this reads from.

## Out of scope

- Substitution and holiday cover, which are
  `handler-vervanging-waarneming` and `substituted-work-reaches-my-work`.
  Take-back is about an assignment that went nowhere, not about a known
  absence.
- The capacity of a status, which is `status-capacity-limit`.
- The map surfaces, which are `case-map-overview` and `case-map-via-maps-leaf`.
  This change reads the area; it draws nothing.
