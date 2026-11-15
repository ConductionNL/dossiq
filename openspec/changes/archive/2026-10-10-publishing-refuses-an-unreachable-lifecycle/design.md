# Design: publishing-refuses-an-unreachable-lifecycle

## D-1. The walk is pure, the publish service decides when to ask

`CaseTypeReachability` takes the declared statuses, the status a new case
starts in, and the moves. It reads no register and holds no collaborator.

That split is the point. `CaseTypePublishService` already owns the one
write, already resolves the effective case type through `CaseTypeResolver`
and already reads the active template. It knows WHEN to ask. The walk only
decides WHAT is unreachable, so it is testable with three arrays and no
fake store.

## D-2. Publication is the only moment this can be asked

A workflow template is written straight to OpenRegister's object API by
the authoring page. No dossiq code runs in between, so there is no save
hook to refuse on. This is the same reasoning `cycleFinding()` was built
on, and it lands in the same place for the same reason.

## D-3. One root cause, one finding

A broken move is also the reason its target is unreachable. Reporting both
tells an administrator to fix one thing twice, and the second sentence
sends them to the status, which is not where the fault is.

So `brokenMoves()` carries the status each broken move was trying to lead
to, and the orphan pass stays quiet about it. The move is the root cause,
the orphaned status is the symptom.

## D-4. The findings are sentences, not codes

They are rendered straight into the Publish dialog, beside the findings
that were already there. Each one names the move by its label, or by its
id when it has no label, and the status by its name.

## D-5. The `*` wildcard is named, not honoured

Three writers store it and no reader honours it. Two ways out:

1. Teach the readers the wildcard. That changes what every stored template
   means, including templates on live instances, so a move that has never
   been offered starts being offered on upgrade. It needs a migration and
   a decision about which of those moves were meant.
2. Refuse to publish it and say so.

This change takes 2. It is the one that cannot surprise a live desk, and
it stops the next author writing another one. Option 1 belongs in
`status-transition-engine`.
