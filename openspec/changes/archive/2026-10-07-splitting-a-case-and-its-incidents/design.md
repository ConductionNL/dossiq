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
