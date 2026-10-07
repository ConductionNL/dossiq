# Design: case-type-rebind

## D-1. The mapping is explicit

The dialog shows the current status and asks for the target status. It
lists every required property of the target at that status that the case
lacks and asks for it. Nothing is guessed from names.

## D-2. Order of writes

1. Validate (D-1); refuse with the rule named.
2. Ask the engine to migrate the run to the target definition; refuse the
   whole rebind if the engine refuses.
3. Write the four fields and the status record with the reason and the old
   binding.
4. Re-arm terms: for each active `deadlineInstance`, complete it with
   reason rebind and create the target definition's instance with the same
   `startDate`, carrying pauses and extensions as `daysImpact` events, so
   `endDateCurrent` is what the target's rule gives from the original
   start. Fixture pair: a 56-day term rebound to an 84-day definition on
   day 20 ends at start plus 84 plus prior extensions.

Steps 3 and 4 are one transaction in dossiq's store call; a failure in 4
rolls back 3 and the engine migration is reversed through its own
reversal.

## D-3. Who may

`dossiq-coordinators` only, declared on the action (ADR-023 rule 2). The
service checks the same group; a direct API call by a handler is refused
403.

## D-4. What does not change

The case number, the folder, the documents, the roles, the notes, the
audit trail. A rebind is a change of blueprint, not a new case.
