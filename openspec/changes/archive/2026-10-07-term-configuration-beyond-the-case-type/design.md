# Design: term-configuration-beyond-the-case-type

## D-1. The overrun is a number, not a flag

"Late" and "on time" is a pass rate. A service manager improving a process
needs to know whether we miss by a day or by three weeks, and those are
different problems with different fixes. So the size of the overrun is
stored, and the pass rate is derived from it rather than the other way
round.

## D-2. A met term is recorded too

Storing only the misses makes the denominator a second query with different
filters, and the two then disagree. Recording the outcome either way keeps
the rate honest.

## D-3. Resolution has an order, and the order is declared

Organisation, then service, then priority, then the case type's own term.
Any order works as long as it is one order everybody can read. Leaving it
implicit is how two municipalities end up with the same configuration and
different dates.

## D-4. The case records which resolution it got

A term somebody disputes has to be explainable a year later, and the
configuration will have changed by then. So the case records the resolution
it used, not just the date it produced.

## D-5. Running statuses are declared, and the engine still owns the clock

Declaring the statuses is not the same as running a clock. Entering a status
outside the declared set suspends the engine timer, and leaving it resumes,
through the same suspend and resume `termijnbewaking-op-engine-timers`
already maps opschorting onto. dossiq declares; the engine counts.

## D-6. A percentage is resolved when the timer is armed

The engine's ladder takes dates. A share of the term becomes a date at the
moment the term is known, which is when the timer is armed. If the term is
extended, the timer is re-armed and the shares are resolved again. This is
why a percentage adds no second escalation mechanism.

## D-7. Days and shares coexist

Some thresholds are genuinely absolute: a statutory notice two days before
is two days before, on every term. So a threshold is either a number of days
or a share, and a ladder may mix them.
