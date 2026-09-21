# Tasks: a-case-note-reaches-the-neighbouring-register

Tier: V1. Kind: capability. Row 6.14. Consumes integriq#2070
(`zgw-documenten`) and the existing `submitDocument` push.

## 1. The envelope

- [x] 1.1 The reserved informatieobjecttype for a case note.
  - **STILL BLOCKED, and it ships as a refusal rather than a guess.** The
    records-management answer is which selectielijst position a working note
    takes, and nobody has given one. A note filed as a document inherits a
    retention term, and the term for a working note is not the term for a
    decision letter: a wrong one silently destroys notes or silently keeps
    them for years.
  - So there is NO default and no seeded type. The app config key
    `note_informatieobjecttype` is unset, and an unset key refuses the push
    and names the key to set (ADR-102). `NoteEnvelopeTest` pins that refusal,
    and `NotePushTest` pins that nothing reaches the adapter when it fires.
  - `x-openregister-archival` is deliberately NOT written on a type this
    change did not create. Declaring a retention here would be the guess the
    block exists to prevent, wearing a schema fragment.
  - `@spec openspec/changes/a-case-note-reaches-the-neighbouring-register/specs/zgw-api-mapping/spec.md`
- [x] 1.2 `lib/Service/External/Zgw/NoteEnvelope.php`: build the ZGW document
  envelope from a note. Title from the first line, author from the writer,
  `taal` nld, the body base64 as `inhoud`, the case as `zaak`, and
  `creatiedatum` from the note's own moment.
  - unit: an empty note is refused before the push rather than sent as a
    document with no content. Five arms, including a long first line being
    cut for the TITLE while the whole note stays in the content, and a writer
    with no display name still naming somebody, because `auteur` is required
    and an empty one is how a note arrives at a neighbouring register with
    nobody's name on it.

## 2. What travels and what does not

- [x] 2.1 Only a note that is not internal is pushed, reading the same marker
  `timeline-entries-default-internal` writes. No second flag: two flags
  disagree the first time somebody edits one.
  - unit: an internal note is not pushed; the same note made external is.
    A third arm covers a note with NO visibility at all, which reads as
    internal, because failing towards keeping a note here is the only safe
    direction: the other way sends a colleague's working note to another
    organisation.
- [x] 2.2 A case that is not bound to an external register pushes nothing and
  records nothing. `isDormant()` is asked FIRST, before the visibility check
  and before the envelope, so a dormant instance never even builds one.
  - unit: with a dormant adapter no marker is written, and `submitDocument`
    is never called.

## 3. A failed push is visible

- [x] 3.1 The push answers its outcome: `no-register`, `not-sent`, `sent` or
  `failed` with the reason, and records it on the case.
  - unit: a refusing adapter answers failed with the adapter's own
    `rejectionReason`, and the case records it as failed.
  - **A `PUSH_DEFERRED` FROM A LIVE ADAPTER IS A FAILURE, and it has its own
    arm.** The dormant case is answered before the adapter is ever called, so
    a deferred push here means a live adapter did not deliver. Reading it as
    a success would put a sent marker on a note that stayed home, which is
    the single thing this change exists to prevent.
- [x] 3.3 The write on the case is CHECKED, which it was not.
  - `CaseTimeline::record()` catches every failure, logs a warning and
    answers `''`. `NotePush` discarded that answer, so an instance with no
    OpenRegister, no configured register or case schema, an unreadable case
    or a throwing write answered `failed` to the caller and wrote nothing on
    the case. Tomorrow the history reads as though nobody ever pushed the
    note, which is the same evidence loss as a note that did not travel
    looking like one that did, one day later.
  - The answer now carries `caseRecord`: `written`, `lost` or `none`. A
    `lost` answer says it in the reason as well, because a flag beside an
    unchanged sentence is a flag nobody reads.
  - An internal note somebody tried to push is now recorded too. Somebody
    asked for it to go out and it stayed, and the case is where the next
    handler looks for that. The dormant branch is untouched: it still
    records nothing, because a marker on every note of every unbound
    instance is a marker nobody reads.
  - unit: two arms on a timeline that cannot write, one on a send and one on
    a refusal, plus the internal-note record and the dormant `none`.
    Mutation checked.
- [x] 3.2 The notes panel marker.
  - **NOT BUILT, and here is the measurement.** A note is an OpenRegister
    COMMENT: storage, the tab and the note's own fields are all in the
    OpenRegister notes leaf (`CnNotesTab` through `CaseNotesTab`), and dossiq
    cannot add a field to one. The library's notes tab carries no slot for a
    per-note badge either, so there is nothing a manifest declaration could
    bind to; declaring one would be a prop nothing reads, which is a failure
    this fleet has shipped before.
  - What ships instead is the outcome on the CASE TIMELINE, which is where
    every other thing that happened to this case already is, so a handler
    looking for what became of a note looks in one place rather than two. The
    requirement is reworded to say that. A per-note badge is a nextcloud-vue
    change.
  - A case with no external register still shows nothing at all, which is the
    half of this task that does hold: the dormant branch records nothing.

## 4. Verification

- [x] 4.1 `tests/e2e/note-sync.spec.ts`: an unbound case records nothing and
  answers `no-register`; a request carrying no note is refused; an anonymous
  caller is refused, which is the least privileged principal that should be,
  because sending a note to another organisation is externally visible and
  not undoable. Written and tagged, not run: there is no Playwright run on
  this box, and an instance with a real ZGW connector is not something a test
  can conjure.
- [x] 4.2 Mutation check: removing the `pushStatus !== 'PUSHED'` guard, so a
  refusal falls through and writes the sent marker, reddens both failure arms
  of `NotePushTest` on their own outcome assertions. Restored, green.
- [x] 4.3 `openspec validate a-case-note-reaches-the-neighbouring-register --strict`.

## What this change decided, for whoever reads it next

A note travels as a `zaakinformatieobject` of a reserved
informatieobjecttype, over the `submitDocument` path that already exists.
ZGW has no note resource, and integriq#2070's six sets carry none, correctly:
the hole was never a missing connector, it was that nothing had decided what
a note IS on the wire.

The push is a DELIBERATE ACT on a note, `POST /api/cases/{caseId}/notes/push`,
rather than a hook on saving one. Measured: note storage is OpenRegister's
entirely under ADR-022, dossiq never reads or writes a note, and OpenRegister
dispatches no note-saved event dossiq could listen for. The `notes#mention`
endpoint beside this one exists for exactly that reason and works exactly
that way, as a call made after the note is already stored. Pushing on save
needs an event that does not exist, which is an openregister row.
