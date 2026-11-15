---
kind: code
depends_on: []
---

# Proposal: the-case-archives-through-openregister

Consumer half of openregister's `archiving-as-a-process-with-sign-off`, parts
one and two, merged as openregister#3736 and openregister#3747. Decision D7,
taken by Ruben: the archiving process lives in openregister. filinq keeps the
bytes and their formats. dossiq keeps the resultaattype.

Ledger rows 4.20, 13.31 and 13.32, and the archiving cluster.

## Why

A case that closes gets an archival future: kept or destroyed, and a date. A
person has to sign that off, and a records manager has to be able to read what
was decided and why.

dossiq shows none of it. The case page has no archival surface at all. A
reviewer has no worklist. The retention block openregister already publishes on
every object read is not on screen anywhere.

And dossiq still derives the archiefactiedatum itself, in
`ArchivalNominationDeriver`, which is the thing D7 says openregister owns.

## What is actually there

Read against `development` at `43150ddf`.

**dossiq computes the archival future in two places, and both are its own.**
`ArchivalNominationDeriver::derive()` maps the resultaattype's
`archivalAction` onto the case's `archiveNomination` enum and adds the
resultaattype's `archivalPeriod` to a base date that
`ArchivalBaseDateResolver` picks per `afleidingswijze`. `CaseResultWriter` calls
it when a case closes in the app, and `ZrcController` calls it when a case
closes over the ZGW API. The values land on `case.archiveNomination` and
`case.archiveActionDate`, dossiq's own properties.

**The handover itself is already openregister's.**
`MigrateArchivalToOpenRegister` moves the retired app-local chain across, places
legal holds and exports the proof of transfer. `OpenRegisterArchivalAdapter`
delegates. The `archief-edepot-handover` spec already says dossiq runs no
archival pipeline of its own. That half stands and this change does not touch
it.

**openregister cannot nominate a dossiq case today.** This is the finding, and
it changes what this change can honestly do. See design D-1.

## What this change does

1. Surfaces openregister's retention block on the case page, as an Archiving
   tab: the nomination and why it was reached, the selectielijst row, the
   outcome once a reviewer has decided, and the reason when nothing could
   nominate the case. The archivist can ask for a recomputation and has to say
   why.
2. Gives a reviewer their own worklist in My Work, over
   `GET /archival/reviews/pending`, and lets them answer destroy, retain or
   transfer with a reason.
3. Surfaces `reviewReminderFrequency` in dossiq's archival settings, so an
   administrator can set how often a reviewer is reminded.
4. Declares the case's archival classification on the case schema, so
   openregister has a selectielijst category to look up.
5. Names the one thing that blocks the deletion of dossiq's own derivation, and
   leaves that derivation standing until it is unblocked.

`DossierZipExporter` is unchanged. The zip stays a convenience for a handler who
wants the dossier on their own disk. It is not the transfer.

## What is not in this change

Deleting `ArchivalNominationDeriver` and `ArchivalBaseDateResolver`. Design D-1
says why: openregister's nomination trigger cannot fire for a case, so deleting
the dossiq derivation would leave every closing case with no archiefactiedatum
at all. That is a silent regression, not a delegation.
