---
kind: config
depends_on: []
---

# Proposal: case-number-and-favourites

Round 2 of the competitor analysis, rows 2.1 and 2.19 of the
`competitor-parity-2026-09` umbrella. Both name openregister as the owner and
both owners have now shipped, so this is dossiq's consumer half of each.

- Row 2.1, "case number from a mask or sequence", owner slug
  `openregister/openspec/changes/generated-identifier` (openregister#3785,
  `e7ac79d1`). dossiq rated `partial`.
- Row 2.19, "favourites and recent items", owner slug
  `openregister/openspec/changes/favourites-and-recent` (openregister#3766,
  `77bdb190`). dossiq rated `no`.

## Why

**The number.** A case number is the thing a citizen quotes on the phone and a
handler types into the search box, and dossiq issues one from three different
places today.

`case.identifier` is filled by an `x-openregister-calculations` expression
using the `sequence` operator. `CaseNumberService` backfills it when that
expression evaluates to nothing. `ComplaintService::generateComplaintNumber`
counts this year's complaints and adds one, which is the failure the archived
`case-identity` change wrote down and could not fix: a count is one deletion
away from handing a live complaint a number another complaint already holds.
Nothing in any of the three takes a lock, so two people filing at once can be
given the same number.

The archived change also recorded a hazard it could not close. OpenRegister's
calculation `sequence` is a counter that starts at one on a fresh install,
while the seeded demo cases already occupy numbers in the hundreds, so a
generated number eventually collides with a seeded one.

`x-openregister-generated` closes both. It takes the number under a lock
inside the create transaction, and a value supplied on create advances the
counter past it, so a seeded or imported number is a floor rather than a
collision waiting to happen.

**The star.** dossiq has no favourites and no recently opened list. A handler
who works the same eight cases for a fortnight reaches them by searching for
them, every time. Competitors do not: OpenCase has a Favourites page and a
Recent page, Zaaksysteem has a Favoriet saved search.

## What changes

- `case.identifier` declares `x-openregister-generated`: sequence `case`,
  format `{year}-{seq:4}`, `resetOn: year`. The `x-openregister-calculations`
  entry for `identifier` goes, so one mechanism issues the number.
- `complaint.complaintNumber` declares the same annotation with its own
  counter: sequence `complaint`, format `KL-{year}-{seq:4}`, `resetOn: year`.
  `ComplaintService::generateComplaintNumber` is retired.
- The New case form still never offers the number, the case page shows it as
  issued, and an update that changes it is refused with OpenRegister's own
  sentence rather than a dossiq paraphrase.
- A star on the case page and on every case row, written through
  `PUT` and `DELETE /api/objects/dossiq/case/{id}/favourite`.
- Favourites and Recently opened chips on `#Cases`, over the `_favourite=true`
  and `_recent=true` lenses.
- Favourites and Recently opened tiles on the Dashboard.

## What this change does not do

**A format per case type.** Row 2.1's candidate C-case-core-30 asks for a
number sequence per record type, so a bezwaar reads `BZW-2026-0001` and a
melding reads `MLD-2026-0001`. The platform mask has no token that reads a
field of the object, so `{caseTypeCode}` cannot be written today. One mask on
the `case` schema is what ships, and the per-case-type prefix is named back to
openregister as the follow-up under `generated-identifier`.

**A second, human number.** C-case-core-20 and the REQ-GID-006 half of
openregister's own change. Nothing to consume yet.

## Who benefits

The handler who quotes a number on the phone, the records officer who needs
the number never to repeat, and the handler who works the same eight cases all
fortnight.

## Impact

- `lib/Settings/dossiq_register.json`: the annotation on two properties, the
  calculation entry removed, both schema versions moved.
- `lib/Service/ComplaintService.php`: the generator retired.
- `lib/Service/CaseNumberService.php`: the same backfill, now the hedge
  against an OpenRegister that predates the annotation rather than one that
  predates the operator.
- `src/manifest.json`: two chips on `#Cases`, two tiles on `#Dashboard`, a
  star widget and a row action on the case surfaces.
- `src/services/favouriteApi.js`, `src/utils/caseFavourite.js`,
  `src/components/case/CaseFavouriteStrip.vue`: new.
- `l10n/en.json`, `l10n/nl.json`: the new labels.
- E2E: `tests/e2e/case-number-and-favourites.spec.ts` (new, tagged, not run
  here).
