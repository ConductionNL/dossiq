# Design: parties and contact moments consume pipelinq

## D1. One gateway, not six resolvers

Six pipelinq services are consumed here. Resolving each of them inline would
put six copies of the same `class_exists` / `method_exists` / log-once dance in
six files, and the day pipelinq renames one of them five of those copies would
still look healthy.

So there is one `Pipelinq\PipelinqGateway`. It answers `service(class, methods)`
with the object or null, and it is the only place in dossiq that names a
pipelinq class. Everything else asks the gateway.

The gateway also answers `isAvailable()`, which is what a surface asks before it
renders "no contact moments" rather than "pipelinq is not installed". Those are
different sentences and a handler needs the second one.

## D2. Why a duck-typed call and not the HTTP route

pipelinq ships both. The HTTP routes exist for a caller that is not in the
fleet; a fleet app calling them would be sending itself a web request, carrying
a session and a CSRF token through its own instance to reach a class already
loaded in the same process.

ADR-041 and gate-27 say an in-fleet command travels as a typed call, and dossiq
already does this twice: `PartyIndicatorReader` against OpenRegister's guard,
`CommitteeDelegationService` against decidiq's event. This change is the third,
not a new pattern.

The frontend is the exception: `src/services/` has no container. Its calls go
over pipelinq's HTTP routes, exactly as `caseParties.js` calls OpenRegister's.

## D3. The indicators are joined, never repointed

`PartyIndicatorReader` reads OpenRegister's `PartyIndicatorGuard` today. The
tempting move is to repoint it at pipelinq's `PartyIndicatorService`.

That would be wrong twice. OpenRegister's guard is also what enforces the
refusal inside the platform, so taking dossiq's reader off it silently drops the
half that still works. And a duck-typed lookup pointed at a name nothing answers
to no-ops rather than erroring: the panel would render, empty, forever.

So the two are JOINED. `PartyRefusalReader` asks both and refuses when either
refuses. Two sources answering the same question can disagree in one direction
only: one of them says no. That is the safe direction, and it is the one this
takes.

## D4. The kinds are fed, not replaced

`PartyVocabulary` ships three kinds and six roles. Deleting them would make an
instance without pipelinq offer an empty picker, which reads as "this case type
accepts no party".

So the vocabulary asks pipelinq first and falls back to its own list. The
fallback is not a degraded mode to be apologised for: it is what the app shipped
last week, and it keeps working.

The ACCEPTANCE goes the other way. dossiq knows its case types and pipelinq does
not, so dossiq declares `dossiq:case:<caseType>` with the ordered kinds and
pipelinq stores that string without parsing it.

## D5. The contact moment is appended, not moved

A contact moment logged in the KCC werkplek writes a dossiq `contactmoment`
today. This change appends the same moment to pipelinq's record as well, with
the direction dossiq already requires and the case as `caseReferences`.

Two records for one call is exactly what this programme is supposed to end, so
it needs saying plainly: this is a BRIDGE, and it exists for the length of one
follow-up change. The dossiq record is what the KCC werkplek reads today; the
pipelinq record is what the fleet reads. The follow-up migrates the first onto
the second and deletes the schema, and it can only do that if the rows exist on
both sides first.

The append is best effort and never blocks the KCC write. A handler logging a
call must not lose it because pipelinq refused something.

## D6. `caseReferences`, not a widened `caseReference`

pipelinq holds the set on `caseReferences` and mirrors the primary onto
`caseReference`. dossiq reads the set and renders the shared marker from it, and
writes through the two filing acts rather than editing the property.

A surface that read only `caseReference` would show one case of three and look
correct on all of them.

## D7. `programme`, not `project`

pipelinq's portfolio object is `programme`, because `project` is planninq's and
a schema slug is global. dossiq links a case to a programme with
`domainObjectType: dossiq:case`, and shows the progress figure with the mode
that produced it.

dossiq declares no programme object and adds no budget field to a case. Round 4
rejected the budget-on-a-case row, and the capability lives in pipelinq instead.

## D8. What "degrades" means, precisely

Every reader here has three answers, not two:

1. pipelinq answered X.
2. pipelinq is present and answered nothing, which is a real empty.
3. pipelinq is absent, which is not an empty.

Surfaces must be able to tell 2 from 3, so every reader returns the
availability beside the data. A panel that cannot tell them apart renders "no
indicators" on an instance that has never been asked, which is the exact failure
mode `projects-leaf.spec.ts` was written to catch on the planninq leaf.
