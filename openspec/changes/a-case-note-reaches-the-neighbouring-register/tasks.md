# Tasks: a-case-note-reaches-the-neighbouring-register

Tier: V1. Kind: capability. Row 6.14. Consumes integriq#2070
(`zgw-documenten`) and the existing `submitDocument` push.

## 1. The envelope

- [ ] 1.1 `lib/Settings/register.d/`: a reserved informatieobjecttype for a
  case note, with its retention declared through `x-openregister-archival`
  the way every other type is.
  - BLOCKED on the records-management answer: which selectielijst position
    a working note takes. Do not guess it: a wrong term silently destroys
    or silently keeps notes for years.
  - `@spec openspec/changes/a-case-note-reaches-the-neighbouring-register/specs/zgw-api-mapping/spec.md`
- [ ] 1.2 `lib/Service/External/Zgw/NoteEnvelope.php`: build the ZGW
  document envelope from a note: title from the first line, author from the
  writer, taal, the body as the content, and the case as `zaak`.
  - unit: an empty note is refused before the push rather than sent as a
    document with no content

## 2. What travels and what does not

- [ ] 2.1 Only a note that is not internal is pushed. Read the same marker
  `timeline-entries-default-internal` writes; do not add a second flag,
  because two flags disagree the first time somebody edits one.
  - unit: an internal note is not pushed; the same note made external is
- [ ] 2.2 A case that is not bound to an external register pushes nothing
  and records nothing. The adapter already answers `isDormant()`; a dormant
  adapter must not write a sync marker that reads like a success.
  - unit: with a dormant adapter no marker is written

## 3. A failed push is visible

- [ ] 3.1 The note carries its push outcome: not sent, sent, or failed with
  the reason. A note that stayed home while the case reads synced is the
  failure this task prevents.
  - unit: a refusing adapter leaves the note marked failed with the reason
- [ ] 3.2 `src/manifest.json`: the notes panel shows the marker on a note
  that failed to leave, and shows nothing at all on a case with no external
  register, because a marker on every note is a marker nobody reads.

## 4. Verification

- [ ] 4.1 `tests/e2e/note-sync.spec.ts`: write an external note on a bound
  case and read the sent marker; make the adapter refuse and read the
  failure with its reason.
- [ ] 4.2 Mutation check: make a refusal write the sent marker, and assert
  the failure test reddens on the marker assertion rather than on setup.
- [ ] 4.3 `openspec validate a-case-note-reaches-the-neighbouring-register --strict`.
