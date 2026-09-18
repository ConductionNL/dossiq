# Design: splitting-a-case-and-its-incidents

## D-1. A split moves, a copy duplicates

`CaseCopyService` is a copy, and it is right for what it does: a follow-up
case that starts from an earlier one. A split is a different act. The
material leaves the first case, and if it does not leave, the split has
produced two cases that both claim the same document.

So the split moves the chosen items and leaves a reference, so the original
file reads as complete and the new case holds the thing itself.

## D-2. The handler chooses, the case type bounds the choice

Which documents belong to which half is a judgment about the content, and
only the handler has it. What may be divided at all is a rule, and it does
not change per case. Declaring the bound on the case type means the handler
is offered the choices that are allowed and no others.

## D-3. Parties divide, they do not duplicate

A party on a case is a role, not a copy of a person. Dividing parties means
each half keeps the roles that belong to it, and a party relevant to both
halves is present on both. The point of the row is that today neither
happens: they stay only on the original.

## D-4. An incident is not a sub-case

A deelzaak has its own number, its own term and its own decision. An
incident has none of those. It is a dated event inside one case with one
term, and the reason to model it is that several of them are what the case
is about.

Making an incident a deelzaak would give every report a beslistermijn it
does not have.

## D-5. An incident owns its own hand-off

The owner of an incident and the owner of the case are different roles and
often different people: the case sits with the area handler while one report
inside it is worked by an inspector. So the incident carries its own
assignee and its own hand-off, and the case's ownership is untouched by it.

## D-6. Order is the date, not the creation moment

A report recorded three weeks late belongs where it happened. The incident
carries the date of the event and is listed by it, with the moment it was
recorded kept separately so the delay is visible rather than hidden.

## D-7. Where the engine's half starts

The change's tasks name three kinds of material a split may divide. dossiq has
had no task table since `remove-casetask`: a task is the workflow engine's
record, keyed by `workflowStepId`, with its own lifecycle. Moving one between
cases is the engine's act and not a row repointed here, so `tasks` is absent
from the divisible set and a request naming it is REFUSED rather than accepted
and quietly ignored.

The same line runs through the incident. `state` is a plain field with an enum,
and this app writes it without judging the transition: which state an incident
may move to from which is a state machine, dossiq already consumes one, and
declaring a second would put two answers behind the same question.

And the work-list count. `IncidentService::openCountOn` answers it for one case.
Surfacing it on the work list means the queue reader counting something that is
not a case, which is that reader's change and not this one.

## D-8. What the build found that the design did not know

**The split does not open the second case.** D-1 reads as though one act does
both. `CaseCopyService` already mints a case and is the right tool for it, so
the caller opens the second case and this divides the material. One act that did
both would duplicate the copy service, and the two would drift on what a new
case gets.

**Parties are `role` rows, not a field on the case.** D-3 says parties divide
rather than duplicate, and the mechanism turns out to be the same as the
document's: a `role` row carries a `case` reference, so dividing is repointing
it. "A party relevant to both halves stays on both" is therefore not a copy but
an absence: the handler does not choose it, so nothing moves it.

**The recording delay has to be counted in calendar days.** From 11 March to 1
April is twenty-one days to every reader, but the clocks go forward in between,
so the elapsed time is twenty days and twenty-three hours and a `->days` count
truncates to twenty. A delay that reads one short for half the year is the kind
of wrongness nobody reports and everybody half-notices, so both moments are
taken to their own calendar date and compared in one zone.
