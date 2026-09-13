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
