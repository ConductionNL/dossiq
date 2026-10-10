# Design: omgevingsvergunning-starts-its-term

## Decision: mirror the bouwactiviteit definition

The register's Omgevingsvergunning is the general Omgevingswet permit, regular
procedure: Ow 16.64 lid 1 sets eight weeks, lid 2 allows one extension of at most
six weeks. That is the rule `td-omgevingsvergunning-bouwactiviteit` already carries,
and the case type's own `processingDeadline` (P56D) and `extensionPeriod` (P42D)
agree with it. The new row copies it, including the pause durations, and is
appended at the end of the list so the file's comment about its first rows stays
true.

## Decision: pin by name rather than widen the sweep

Widening the sweep to the whole register would fail on ten other case types whose
legal basis is not settled here. The test pins Omgevingsvergunning by name, with
its duration and extension, and the proposal lists the rest.
