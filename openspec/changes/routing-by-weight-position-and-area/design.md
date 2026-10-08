# Design: routing-by-weight-position-and-area

## D-1. Weight is a property of the membership, not of the person

The same handler may carry a full share of intake and a quarter share of
objections. Putting the weight on the person would force one number for
both. Putting it on the membership of a pool lets each pool say what it
expects, which is also what an administrator can defend.

## D-2. Weight multiplies the existing strategies, it does not replace them

Round robin with weights is still round robin. Least loaded with weights
still measures load. Adding a third strategy would leave two unweighted ones
in the list for somebody to pick by accident.

## D-3. A position is a role inside a team

An organisation-wide role for every senior of every team is how a role list
reaches forty entries that mean six things. A position is the role plus the
team, resolved together, so the vocabulary stays small.

## D-4. Take-back is a timer, and a record

A sweep that reassigns work is unpredictable to the person it takes work
from. An armed window, declared on the pool, is readable before it fires.
And the take-back is written down: who had it, why it came back, who has it
now. Otherwise the case looks like it was never routed to the first person.

## D-5. The area is held on the case, not resolved per query

Resolving a boundary on every routing decision makes routing depend on an
external service being up. Resolving it once, when the address is set or
changed, keeps the routing decision local and makes the value filterable.

## D-6. A case outside every boundary is a case, not an error

Addresses fall outside boundaries: new developments, water, an address
abroad, a case with no address at all. So the case type declares a fallback,
the routing says it used it, and nothing waits for a person to notice that
the routing silently did nothing.

## D-7. The boundaries are administered, not hardcoded

Wijk and buurt boundaries change, and a municipality may use its own
division instead of the national one. So the boundary set is configuration
with a source and a date, which also means the value on a case can be
explained by the boundaries in force when it was resolved.
