# Design: case-recycle-window

## D-1. The guard runs before the window, never instead of it

D10 chose both, and the order matters. `case-delete-guard` refuses a
delete that would strand a deelzaak, a besluit or a running term. The
recycle window catches the delete that was allowed and wrong anyway.

A guard alone leaves an accidental delete unrecoverable. A window alone
lets somebody delete a case that half the register points at, and then
discover it on day thirty-one.

## D-2. A deleted case reads as deleted, and says for how long

Vikunja purges soft-deleted tasks after thirty days and says so. A case
that vanishes from the list and reappears nowhere is indistinguishable
from a case that was destroyed, so a handler who deleted the wrong one
does not know whether to panic.

So the case list has a deleted lens, the case reads deleted, and the
remaining window is on it as a date rather than as a duration nobody can
compute.

## D-3. Destroying is a second act with a name attached

C-access-and-privacy-50 asks that destroying a case destroys the process
data behind it: "a destroyed case whose task history, notes and audit rows
survive is not destroyed". That is openregister's cascade to perform, and
dossiq's to declare the scope of.

The act records who decided, because the question an auditor asks in 2029
is not whether it was destroyed.

## D-4. Two clocks, kept visibly apart

The AVG says delete when the lawful purpose ends. The Archiefwet says keep
for N years. They disagree on purpose, and a product with one date field
answers whichever question it was built for and gets the other wrong
silently.

So the case carries both, labelled, and neither is derived from the other.
`ArchivalNominationDeriver` already derives the archive side; the lawful
purpose side is its own.

## D-5. Who may destroy is a case-type declaration

C-documents-20 restricts document deletion to the record manager, and its
clause says why the pattern is worth copying: "destructive acts scoped to
one named role is a control we state nowhere".

The role is declared on the case type, because a melding and a bezwaar do
not need the same seniority behind a destruction.
