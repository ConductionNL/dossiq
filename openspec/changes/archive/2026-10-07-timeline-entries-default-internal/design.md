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

`publicEntries()` is an in-process read, and an outsider has no session to
make one with. So the same filter runs on both sides of the boundary, and
the two places are deliberate rather than accidental:

- dossiq's consumers, the contribution provider among them, call
  `CaseTimeline::publicEntries()`, which takes no visibility argument. It
  answers empty only for absences it establishes; a read that throws is
  logged and rethrown, and the provider decides at its boundary.
- an outsider reads openregister, which owns the feed and its flag (row
  6.15). Since #2888 a case share mints an openregister access link and
  nothing mints a case token, so the live anonymous surface is
  `GET /api/public/links/{anchor}`. openregister's `PublicTimeline` is the one
  reader behind it, and behind the case-token resolve as well.

Neither transport hands the caller a visibility parameter, and both project
an entry down to five keys (`id`, `kind`, `message`, `fields`, `occurredAt`),
so an author, a raw source or the entry's own flag cannot travel by either
route. Building that reader closed a leak the access-link surface already
had: it served each public note with its author's user id and display name,
and it read notes only, so a kinded record never reached an outsider.

`PublicStatusPage` renders the `timeline` key of whatever payload it is
handed, in the same five-key shape. It still resolves a case token, which no
outsider reaches since #2888; re-pointing the public pages at access links is
another change's work, and this one does not touch the SPA route. The e2e
spec therefore reads the live surface, the access link, directly.

The openregister half is ConductionNL/openregister
`feat/public-token-carries-the-public-timeline`.
