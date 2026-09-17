---
kind: feature
depends_on: []
---

# Proposal: data-subject-requests-drive-the-platform

The consumer half of openregister's `data-subject-rights-across-the-instance`
(openregister#3759, and part 2 #3800). The platform shipped the erasing; this
is the app that asks it to.

## Why

A person writes to the gemeente asking what we hold about them, asking us to
correct it, or asking us to erase it. What arrives is a letter, not an API
call, so it needs a handler, a statutory month, a status, a timeline and an
audit trail, like everything else a gemeente is asked to do. dossiq has all of
that already and was using none of it for AVG requests.

The erasing itself is not ours. ADR-047 puts the AVG capability in
OpenRegister, and openregister#3759 and #3800 built it: an erasure preview
over the instance-wide PII index, an approval, a run, and a subject export.
An app that builds its own second answer to "what do we hold about this
person" is exactly what that ADR exists to prevent, because the two answers
then disagree and a disagreement about personal data is itself a disclosure.

So the question this change settles is the same ownership question
`page-topology-cleanup` settles for the processing-activities page, settled
for the requests themselves: dossiq runs the case, OpenRegister does the
erasing and the exporting.

## What changes

- A `data-subject-request` case type covering inzage, correctie and
  verwijdering, with `processingDeadline: P1M` (AVG art. 12(3), with the
  `P2M` extension it allows) and six statuses.
- A `dataSubjectRequest` block on the `case` schema holding the request kind,
  the subject, the platform's preview id and digest, the approval, and the run
  outcome. Every value in it is one the platform returned.
- `Service/Gdpr/PlatformDataSubjectRights`: the one door onto OpenRegister's
  preview, approval, run and subject export, resolving the services by name so
  an older OpenRegister is reported honestly rather than throwing.
- `Service/Gdpr/DataSubjectRequestCase`: the case side. It writes the preview
  onto the case, refuses a run the case has not approved, records the outcome
  and writes one timeline entry of a newly declared AVG kind.
- A four-eyes guard: the approving transition declares `notPerformedBy`
  against the preparing act, so the person who took the preview cannot approve
  it. The engine reads the performer from the case's own status record chain.
- A new `erasureComplete` transition precondition, so a verwijdering case
  cannot close while the platform reports the erasure incomplete, and the
  reason names the records it did not reach.
- Four per-case endpoints and an AVG tab on the case page that shows the
  counts, every protected item with its ground, and the export download.

## What this change does not do

**Any erasing.** There is no erasure, pseudonymisation or export engine in
dossiq, and this change adds none. `Recycle/CaseDestructionService`, the one
thing in the app that destroys, already works this way too: it decides, and
OpenRegister carries it out (openregister#3724).

**Counting what the instance holds.** The preview names no subject in its
request body; the subject comes off the case on the server. A body would be a
door into counting what the instance holds about a person the caller cannot
otherwise reach.

**The correctie path beyond the case.** A correction is handled as a case and
carries the statutory month, but the platform has no correction act to drive,
so nothing is asked of OpenRegister for it yet.

## Who benefits

The handler who has to answer a data subject within the month and needs to say
what was erased and what could not be, the FG who has to show a second person
approved every erasure, and the records officer who needs the answer to still
be readable in 2029.

## Impact

- `lib/Settings/register.d/54-data-subject-request.json`: new.
- `lib/Settings/templates/avg-verzoek.json`: new.
- `lib/Settings/dossiq_register.json`: one description, naming the new
  `erasureComplete` dependency kind.
- `lib/Service/Gdpr/`: `PlatformDataSubjectRights` and
  `DataSubjectRequestCase`, both new.
- `lib/Service/Timeline/TimelineKinds.php`: the `avg-verzoek` kind.
- `lib/Service/Transitions/TransitionPreconditions.php`: the
  `erasureComplete` kind.
- `lib/Service/TemplateLibraryService.php`: creates the declared
  `workflowTemplate`, which no bundle in the library was doing.
- `lib/Controller/DataSubjectRequestController.php` and four routes.
- `src/services/dataSubjectRequestApi.js`,
  `src/views/cases/components/DataSubjectRequestTab.vue`, `src/registry.js`,
  `src/manifest.json`.
- `l10n/en.json`, `l10n/nl.json`: the new labels.
- E2E: `tests/e2e/data-subject-requests-drive-the-platform.spec.ts` (new,
  tagged, not run here).

## Not a competitor-parity row

`competitor-parity-2026-09` has no AVG or data subject row, so this change is
not added to that umbrella's index. It is the consumer half of a platform
change, and its owner is openregister#3759 rather than a ledger candidate.
