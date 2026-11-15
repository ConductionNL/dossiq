# Design: what-a-status-declares

## D-1. A derived status is not a transition somebody may also pick

If a status can be both computed and chosen, the two disagree within a week
and nobody knows which one is the record. So a status that declares its
conditions is derived, and it leaves the list of transitions a person can
pick. Statuses that are genuine judgments keep being picked, which is most
of them.

## D-2. A derivation that cannot fire has to say why

"Complete" that never arrives is worse than no derivation, because the
handler has nothing to act on. The unmet conditions are readable on the
case, which also makes the derivation testable by a person rather than only
by a test.

## D-3. Waiting on the applicant and waiting on a third party are different facts

The Awb treats them differently: a hersteltermijn suspends the beslistermijn
and an advice request does not. Conflating them in one value means the
system cannot decide whether the clock should stop, and it means a team
lead's queue count includes work nobody on the team can move.

The existing `role` values stay, because the shipped flow reads them. The
new declaration sits beside them and is what the counts and the term
decisions read.

## D-4. A maximum dwell is a timer, not a sweep

A daily sweep that recomputes every case is the shape
`termijnbewaking-op-engine-timers` is removing. Entering a status with a
declared maximum arms a timer; leaving it cancels the timer. The breach is
an event, not a discovery.

## D-5. A dwell breach is not a term breach

A case can breach its status maximum while its statutory term is
comfortable, and that is the point of the row: it catches the case that is
stuck inside a term that is long. So the breach has its own name, its own
notification and its own filter, and it never touches the term's own
state.

## D-6. The number is held on the case because that is where it is used

An aggregate on a dashboard answers a manager's question. A handler sorts a
work list. The same measurement has to be a field for the second use, so the
current dwell and the totals per status are written on the case as the
status changes, and the analyzer reads them rather than recomputing its own.

## D-7. One clock, the working calendar

Two dwell numbers on two clocks is the failure
`dwell-time-on-the-working-calendar` describes. The field held on the case
counts working time on the organisation's calendar, and the wall clock stays
available beside it as that change specifies.

## D-8. What shipped on two clocks, and why it is said out loud

D-7 asks for one clock. What shipped is one AUTHORITY and one convenience,
which is not the same thing and is worth naming rather than discovering.

The breach is the engine's. The timer is armed in its own `businessDays` unit
over the calendar the organisation administers, so the moment a case is
reported stuck is decided by the same calendar every statutory term is decided
by. The number held on the case is counted by dossiq's own
`WorkingDayCalculator`, because the engine exposes projection and no count
between two dates. On an organisation whose calendar differs from the Dutch
national one the two can disagree by a day.

That is a smaller wrong than the alternatives. Counting on the wall clock
would put a second measurement of the same thing in front of the same person,
which is the failure `dwell-time-on-the-working-calendar` describes. Walking
the engine day by day to count would be sixty engine calls per case on a work
list of four hundred rows. The honest fix is a count operation in
`working-calendar-admin`, and until it exists the divergence is written in the
service that causes it.

## D-9. A derivation moves the case; it does not write the history

The derivation runs on the save that made it true, which means a pre-persist
listener, which means anything it writes elsewhere would survive a save that
then failed. So it writes the status and the dwell fields into the same save
and writes no `statusRecord` at all: a history carrying a move that never
happened is worse than a history that is quiet about one that did.

It is not invisible. OpenRegister's audit trail of the case records the change
with its actor, and the per-status totals the same write settles are what the
process mining page reads. The day the case timeline is asked to show
derivations, the shape is a post-persist listener comparing the two statuses,
not a second write from here.
