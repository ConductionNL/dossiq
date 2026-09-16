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

## D-4. One filter, two transports

`publicEntries()` is an in-process read, and the public status page has no
session to make one with. So the same filter runs twice, in two places, and
the two places are deliberate rather than accidental:

- dossiq's consumers, the contribution provider among them, call
  `CaseTimeline::publicEntries()`, which takes no visibility argument.
- the citizen's browser resolves an opaque token against openregister's
  `GET /api/public/case-tokens/{token}`, which now carries the object's
  public entries. openregister owns the feed and its flag (row 6.15), so
  the filter belongs on its side of that boundary, applied before the
  payload leaves the server.

Neither transport hands the caller a visibility parameter, and both project
the entry down to five keys, so an author, a raw source or the entry's own
flag cannot travel by either route. The openregister half is
ConductionNL/openregister `feat/public-token-carries-the-public-timeline`.
