# Handover: the three halves dossiq does not build

Round 4 discovery cluster 3 carries three candidates whose surface belongs to
another app. dossiq declares into them and builds no half of them here. This
file is the handover, so the next lane in those repos starts from a name and a
candidate id rather than from a reading round.

Nothing below is blocked on dossiq. Each is a change somebody opens in the
owning repo.

## C-configuration-59, reusable blocks in the form builder

**Owner: buildiq.** A form is built from reusable blocks rather than from empty
fields. The register already names buildiq for rows 3.12 and 11.4, and
`openspec/specs/form-editor-logic/spec.md` owns the builder.

That spec does not mention blocks today, so this is a new change and not an
amendment. The follow-up lane opens it in buildiq.

**dossiq's half, already shipped:** a case type points at a form built there.
`caseType.handling.intakeScreen` names the page a case of that type is
registered on, and `src/utils/intakeScreen.js` resolves it against the manifest.
A case type naming a page the manifest does not have falls back to the standard
screen, so a block library that renames a page cannot route a handler nowhere.

## C-configuration-77, the citizen's home page from administered tiles

**Owner: portaliq.** The portal home page is composed from tiles an
administrator places. dossiq hosts no portal page at all, which the archived
`move-portals-to-portaliq` settled.

**dossiq's half, already shipped:** dossiq contributes its case types to the
tile source. `GET /api/starter/shipped/caseType` answers every case type with
the set it came from, and the ordinary case type API answers the rest. A tile
that names a retired case type can read that state from
`CaseTypeLifecycleState`, so a portal does not offer a case type that takes no
new cases.

## C-configuration-85, free text and HTML on a page

**Owner: buildiq.** The owner of a page places free text and HTML on it. The
register names `page-layout-per-case-type` for rows 11.6, 11.7 and 11.8, and
that slug has no artefact on buildiq `development`.

So this is also a new change in buildiq. It pairs naturally with
C-configuration-59: a free text block is a block.

**dossiq's half:** none beyond the intake screen reference above. dossiq
declares which page a case type uses and nothing about what is on it.

## What a lane in those repos needs from here

- The candidate ids, which are in the headings.
- `caseType.handling.intakeScreen`, declared in
  `lib/Settings/register.d/48-starter-content.json`. It is a plain page id, not
  a route and not a URL.
- The fallback rule, which is that an unknown page id resolves to the standard
  intake screen rather than to nothing. A block library that renames pages is
  therefore safe to ship before dossiq's case types are updated.
