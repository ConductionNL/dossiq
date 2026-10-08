# Design: status-capacity-limit

## D-1. The limit is on the status type, the check is a guard

`statusType.capacity` is configuration; `CapacityGuard` is the evaluator
that reads it. This is the shape `StatusChecklistGuard` already has, and
`GuardRegistry`'s own comment records why it matters: "The list it reads
is authored on the STATUS, so a transition that never names it is still
subject to it". A capacity authored on a status must bind every route into
that status, including a transition written before the capacity existed.

## D-2. The count is of cases in the status, not of anything else

Cases whose `status` is the status, within the same case type, excluding
cases in a final status. A count that included closed cases would make
every busy status permanently full within a year.

## D-3. Out is always allowed

The guard runs on the transition into a status. A transition out of a full
status is not evaluated against its capacity, so a full column can always
be drained. Without this rule the first status to fill up stays full.

## D-4. The refusal names the number

"Status In behandeling is full: 12 of 12 cases." A handler who reads that
knows what to do. Vikunja answers 412 with code 10004; dossiq answers 409,
because the request is well formed and the target is the thing that is
full, and dossiq's refusals carry a status and a message
(`refusals-carry-a-status`) rather than a numeric code the UI has to map.

## D-5. Bulk refuses per case

A bulk transition of ten cases into a status with three places free moves
three and reports seven refused, each with the same sentence. Refusing the
whole selection would make the action useless on exactly the day somebody
needs it.

## D-6. The column shows the number before anybody tries

The board column header and the status chip show `count / capacity`. The
refusal is the backstop; seeing the number is what stops the attempt.

## D-7. What the build found that the design did not know

**D-2's count rule was two rules that did nothing.** It said "cases whose
`status` is the status, within the same case type, excluding cases in a final
status". Both qualifiers are already implied and one of them is actively wrong.
`statusType` carries its own `caseType`, so a status id already names one
lifecycle; and where a child case type INHERITS a parent's status, the cases of
both types really are in that one status, so filtering by case type would let
two types put twenty-four cases into a status whose limit reads twelve. The
final-status exclusion reduces to one thing: a case counted towards a status's
limit is by definition in that status, so a closed case only reaches the count
when the status being entered is itself the closing one. The guard therefore
skips a final status outright, which is also the right rule on its own terms: a
case type that could not close its thirteenth case would be worse off than one
with no limit at all.

**D-6 cannot be satisfied on this board as written.** A board column merges
every non-final status type sharing a NAME, across every case type
(`WorkflowBoard.vue`), and a capacity is authored on one status type. A merged
column therefore has no single limit to show, and a header reading "9 of 12"
beside an engine that refuses at 4 is worse than no header number, because the
number is exactly what a handler plans against. So a merged column shows the
bare count, and the drop refusal reads the CONCRETE target status, which the
board already resolves per case type before it posts the move.

**A case must not take a seat from itself.** The count excludes the case being
moved. Without it a self-transition, or a re-run of a transition that already
landed, refuses a case for occupying its own place.

**An unreadable count allows the move.** The checklist guard beside it fails
closed, because a required item is a rule about the case. A capacity is a rule
about the queue, and a planning aid rather than an authorization: refusing work
because a read failed would stop a desk over an outage, and the statutory term
keeps running either way.

**D-5 needed no code.** `TransitionCasesAction::apply()` already takes one
object and answers `refused` with the first failed guard's message, so the
per-case behaviour the design asks for is what the bulk action already does.
`CapacityGuardWiringTest` pins the shape rather than adding a second one.
