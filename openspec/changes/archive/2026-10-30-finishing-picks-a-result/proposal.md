---
kind: code
depends_on: []
---

# Proposal: finishing-picks-a-result

## Why

In rehearsal R2 (Woo case, 15 Oct) the handler finished a case from Volgende stap > Afronden. The form asked for "Resultaattype" in a free text field. The handler typed "Deels toegewezen", the server wrote that text as the result's `resultType`, OpenRegister refused it (`should match format 'uuid'`) and the handler read a 500 with the English "The case could not be changed."

Closing transitions (#3932) and stage clicks (#3938) already offer the case type's results in a picker. The Finish and Abort acts did not, because `/acts` carried no result choices, so the dialog fell back to the free field.

## What Changes

- `/acts` answers `resultTypes`: the results a case of this case type can close with, parent case types included (`CaseResultWriter::resultTypeChoices`).
- Finish and Abort offer those results in a picker. The free text field is gone from the lifecycle dialog.
- A close naming a result that is not one of the case type's results is refused with 422 `result-type-unknown` and a translated sentence, on both the act and the transition path, before anything is written.
- The acts endpoint's fallback 500 sentence is translated.

## Impact

- `lib/Service/Transitions/CaseResultWriter.php`, `lib/Service/Lifecycle/CaseEndingActs.php`, `lib/Service/Lifecycle/CaseActsOverview.php`, `lib/Controller/CaseActsController.php`.
- `src/utils/caseActsMenu.js`, `src/dialogs/CaseLifecycleMenuDialog.vue`, l10n nl/en.
