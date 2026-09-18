# Design: case-split-surface

## D-1. The picker asks what may be divided

The dialog does not list the three parts and let the server refuse two of
them. It asks `GET .../split`, which answers `CaseSplitPolicy::allowedFor()`
and the rows behind each allowed part. A checkbox for something the server is
about to refuse is a checkbox that wastes a split: the handler ticks it,
confirms, and learns the rule only after the attempt.

## D-2. The rows are read before they are planned

`CaseSplitPlan` refuses a row whose own `case` names a different case, which
is what stops a handler moving a document off somebody else's case by editing
one field in the request. It can only do that when it is handed the STORED
row. So the performer reads the ticked ids and passes rows, never the ids the
client sent.

## D-3. The refusal sentence is not rewritten

`CaseSplitPolicy::whyRefused()` already names what may still be divided,
because a handler told only what they may not do guesses at the rest. The
controller passes that sentence through and the dialog shows it verbatim.

## D-4. What did not move is named

A ticked row that turned out to sit on another case comes back as
`refusedRows`. A selection that half happened with no word about the rest is
the state nobody can reconstruct a year later.

## D-5. No part on both halves

An earlier draft of this dialog offered "belongs to both cases" for a party.
`CaseSplitPlan` repoints a row and has no notion of one on both cases, so that
control would have been a switch that did nothing. It is gone rather than left
looking like a feature.
