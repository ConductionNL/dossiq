# Design: the-case-archives-through-openregister

## D-1. openregister's nomination trigger cannot fire for a case, and that is measured

openregister derives a nomination from `ArchivalNominationListener`, which
watches `ObjectTransitionedEvent` and asks
`ArchivalNominationService::isTerminalState()` whether the state reached is one
the schema declares. That method reads
`configuration['x-openregister-lifecycle']['final']` and does an `in_array`
against the value of the lifecycle field.

Two things stop it on a dossiq case, and each is enough on its own.

**A case's finality is data, not schema.** `case.status` is a `$ref` to a
`statusType` row, so the value that reaches `getTo()` is a UUID belonging to one
instance. `final` is a static list of enum values, validated against the field's
enum by `LifecycleAnnotationValidator` (`lifecycle-final-not-in-enum`). There is
no UUID that can be written into it. `initial` has a dynamic
`{from, field}` form on the same annotation and `final` has no analogue. dossiq
already carries the answer as data: `statusType.isFinal`, which openregister
materialises onto the case as the calculated `isFinalStatus`.

**The schema's archive block is not the archival annotation.**
`ArchivalNominationService::nominate()` reads `$schema->getArchive()`, the
schema's `archive` column, and returns `not_applicable` unless it holds
`enabled: true`. dossiq declares `x-openregister-archival`, which the import
path puts in `configuration`, not in `archive`, and whose shape is
`{retention: {default, rules}}`. The two never meet.

So the closure path is dark. Nothing nominates a case, no log line says so, and
an instance where it never fired looks exactly like an instance with nothing to
nominate.

**What follows for this change.** Deleting dossiq's derivation now would take
the archiefactiedatum off every closing case and put nothing in its place. That
is the deleted-side-effect shape, not a delegation. The derivation stays, marked
as standing in for openregister, until openregister can answer finality for a
provider-mode schema. The ask on openregister is one of:

- a dynamic `final`, matching the `{from, field}` form `initial` already takes;
- or a finality predicate on `LifecycleActionProviderInterface`, which is where
  a provider-mode schema's state machine already lives.

Either lets dossiq declare what it already knows. Neither is dossiq's to build.

## D-2. Read the retention block, never recompute it for display

`@self._retention` is published on every object read and now carries
`selectionListRow`, `nomination` and `outcome`. The Archiving tab renders that
block and nothing else.

A second derivation in the browser would eventually disagree with the stored
one, and a records manager reading a disposal date has no way to tell which of
the two they are looking at. The tab therefore shows the stored values, the rule
that produced each, and the moment it was written.

An absent nomination and an unnominatable one are drawn differently on purpose.
`status: unnominatable` names the missing source and is somebody's problem
today. A case nobody has closed yet has no nomination block at all and is not.

## D-3. Recomputing is an act, so the reason is a required field

`POST /archival/objects/{id}/nomination/recompute` refuses a request without a
reason with a 400. The tab asks for the reason before it enables the button
rather than sending and rendering the refusal, and it says who the act will be
recorded against.

## D-4. The worklist is the reviewer's own, so it is not filtered client-side

`GET /archival/reviews/pending` reads the session user id and answers that
user's undecided entries. There is no user id in the request to tamper with, and
My Work passes none. A filter in the browser over a wider list would be a
different, weaker thing wearing the same label.

Three answers, one reason field. Retain also asks for the new
archiefactiedatum, which the endpoint requires and refuses without.

## D-5. The zip is not the transfer

`DossierZipExporter` builds a dossier a handler can download. That is a
convenience and it stays exactly as it is. The transfer to an e-depot is a
decision a reviewer signs, recorded on the destruction list, and read back on
the case as `outcome.transferListUuid`. Naming the zip an archive action is what
ledger row 4.20 asks dossiq to stop doing, and the Archiving tab is where the
real answer now lives.
