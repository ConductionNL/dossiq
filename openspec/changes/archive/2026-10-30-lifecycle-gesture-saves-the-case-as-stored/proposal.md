---
kind: code
depends_on: []
---

# Proposal: lifecycle-gesture-saves-the-case-as-stored

## Why

On the review instance (10 Oct, finding B1) pausing or extending a case with a
statutory term answered HTTP 500 "Could not change the case", while the pause or
extension had in fact been applied. The log said `CaseLifecycleController: gesture
failed - Cannot modify readOnly property: deadline`.

`CaseLifecycleService` read the case, ran the term gesture, and then saved the
case array it read at the start. The term gesture saves the TermijnInstance, and
the deadline mirror (`CaseDeadlineMirror`) then writes the term's new end onto the
case: `statutoryDeadline`, and `deadline` inside that save. The array saved
afterwards still carried the old `deadline`. OpenRegister refuses any change to a
readOnly property, so the journal write failed. Had it not, the stale
`statutoryDeadline` would have been written back silently.

The 500 also carried only an English sentence, which the case page showed as is
on a Dutch screen.

## What Changes

- Every lifecycle gesture (suspend, resume, extend, reopen) reads the case again
  when it writes the journal, after the term has moved, and applies only the
  fields the gesture owns (`plannedEndDate` and `extensionCount` for extend; the
  status, end date and archival claim for reopen).
- The unexpected-failure answer of `CaseLifecycleController` carries
  `code: change_failed`, which the page translates. The English `error` text is
  the page's existing msgid, "The case could not be changed."

## Impact

- `lib/Service/CaseLifecycleService.php`, `lib/Controller/CaseLifecycleController.php`.
- No schema or register change.
- Frontend: `src/utils/caseLifecycleHelpers.js` should map `change_failed` to
  `t('The case could not be changed.')` (already in `l10n/nl.json`). That file is
  owned by the frontend lane.
