# case-actions-on-the-case-page

## Summary

"Bezwaar maken" and "Klacht indienen" stood at the bottom of the resident's
overview, where they knew no case. They move to the case page, where each
carries the case on screen into the action (`withRecord`), so the objection
or complaint arrives tied to that case. The case page also orders its blocks
as the Zaak board does: Gegevens before Stukken and "Wat er is gebeurd".

## What changes

- The overview declares no call to action any more.
- The case page declares, after "Bericht sturen", "Bezwaar maken" and
  "Klacht indienen", each with `withRecord: true`.
- `createBezwaar` names `againstCaseId` as its `recordField`.
- `createKlacht` accepts `againstCaseId` too, as its `recordField`, guarded
  against the citizen's own cases but not required: a klacht may be about
  the municipality in general and is still filed from the "Klacht indienen"
  page without a case.
- The case page reads: question, steps, Gegevens, Stukken, timeline, the
  three calls to action, the case screen.

## Impact

Declarations only (lib/Portal/PortalPages.php, lib/Portal/CitizenManifest.php).
No schema change: `portaalVerzoek.againstCaseId` exists.
