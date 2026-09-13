# Design: timeline-entries-default-internal

## D-1. Internal unless a writer says otherwise

Each dossiq writer sets the flag: forms default internal with a toggle;
outbound message writers set public; the status-change writer sets public
only when the status has a `publicLabel` (`citizen-status-labels`).

## D-2. One reader for both surfaces

`CaseTimeline::publicEntries(caseId)` reads the feed filtered on public
and is called by the contribution provider and by `PublicStatusPage`.
Nothing renders an internal entry outside the instance.

## D-3. Flipping is audited

Changing an entry from internal to public is an edit the platform audits;
dossiq adds nothing.
