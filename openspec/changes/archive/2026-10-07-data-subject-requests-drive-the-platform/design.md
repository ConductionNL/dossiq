# Design: data-subject-requests-drive-the-platform

## D-1. The request is a case, not a new kind of thing

A data subject request has a deadline, a handler, a status, a decision and an
audit trail. That is a case. Giving it its own entity would mean a second
worklist, a second term engine and a second timeline, all so that the thing a
handler treats like a case could be stored differently from one.

So it is a case type, `data-subject-request`, with the request kind
(`inzage`, `correctie`, `verwijdering`) as a field on the case. The statutory
month rides the existing term model as the case type's own
`processingDeadline: P1M`, with the `P2M` extension AVG art. 12(3) allows.
dossiq declares no clock of its own for it.

## D-2. One door onto the platform, resolved by name

`PlatformDataSubjectRights` is the only class in dossiq that knows
OpenRegister's GDPR services exist. Every method is a call into
`OCA\OpenRegister\Service\Gdpr`, and the shapes that come back are the
platform's own: `report.counts`, `report.items`, `report.protected` and the
`digest` on a preview, and `{destroyed, pseudonymised, withheld, refused,
failed, complete}` on a run.

The services are resolved by name through the container rather than
type-hinted. OpenRegister is a hard dependency, but its GDPR services arrived
in 0.2.x, and an instance on an older build has the app and not the services.
Resolving by name lets `isAvailable()` answer that honestly, instead of the
container throwing while constructing a controller. The cost is that no
analyser can see the class, which is why the refusal is read through
`call_user_func` behind an `is_callable` guard rather than a `@var` docblock
claiming a shape phpstan cannot check.

## D-3. A refusal keeps the platform's own rule name

OpenRegister refuses with `erasure-preview-unknown` (404),
`erasure-not-approved`, `erasure-already-run` and `erasure-preview-stale`
(409). Each of the four means something different to the handler standing in
front of the case, and each has a different fix.

Collapsing them into "the erasure failed" would leave a handler retrying a
stale preview for ever, because the one thing that fixes it, taking the
preview again, is exactly what the generic sentence does not say. So the rule
and the sentence are carried across unchanged and only the exception type
changes.

## D-4. `report.protected` is rendered in full, not counted

The panel shows three counts, and then every protected record by name, with
its ground, its legal basis and what can still be done about it.

A count of what cannot be erased is not an answer a handler can give the
person who asked. "Fourteen records are protected" ends the conversation
without answering it; "your subsidiedossier is held for seven years under the
Archiefwet, and you can ask the archivist for an early disposal" is the
answer. The list is the point of the panel, and the counts sit above it rather
than instead of it.

## D-5. The four eyes rule is a declaration the engine already reads

The approving transition declares `notPerformedBy: {act, askInstead}`, naming
the LABEL of the preparing transition. The engine reads the performer from the
case's own status record chain.

It is deliberately a negative rule about an act rather than a role: the same
person legitimately approves other cases they did not prepare, so a role guard
would be both too strict and too loose. And it reads the chain rather than a
flag, because the first thing that happens to an `approved` flag is that
somebody sets it.

**This exposed a bug worth naming.** Every bundle in
`lib/Settings/templates/` shipped statuses and no transitions at all, so a
case type activated from the library had an ordered status list with nothing
to declare a guard on. `TemplateLibraryService` now creates the declared
`workflowTemplate`, resolving `fromStatusName`/`toStatusName` against the
statuses it just created. Without that, the four eyes rule here would have
been a rule nothing read, which is not a weaker rule; it is no rule.

## D-6. An incomplete erasure withholds the close, and names what is left

A new `erasureComplete` dependency kind reads the case's `erasureOutcome`,
which is the run report the platform wrote, and withholds the closing
transition until the platform calls the erasure complete.

The reason names the records that were withheld rather than reporting that a
condition failed, because those records are exactly what the data subject is
owed an answer about. A case with no run at all is withheld too: a
verwijdering that closed clean without one would report an erasure that never
touched an object.

The three closing transitions are each pinned to their request kind with
`fieldEquals`. Without that pin a handler on a verwijdering could take "Inzage
afronden" and close straight past the erasure guard.

## D-7. `downloadable` is the platform's answer, never a date comparison

An export has a seven day life. The panel offers the download only while the
platform says the file is ready and unexpired, and never computes that from
`expiresAt` itself.

An export still being assembled is neither downloadable nor expired. Calling
it expired because a date has not arrived yet sends a handler off to ask for a
second export that will be no readier than the first.

## D-8. The AVG tab is declared on every case, and reads the kind itself

A sidebar tab list is one declaration on the case page, and gating it on a
field would need a per-case condition the host does not evaluate there. So the
tab is declared once and the panel reads `dataSubjectRequestType` itself,
showing the erasure half, the export half, or an empty state saying this case
is not a data subject request.

The empty state is not cosmetic: it is what stops the panel asking the
platform about a case that never named a subject, which would otherwise be one
request per case opened, answering nothing.
