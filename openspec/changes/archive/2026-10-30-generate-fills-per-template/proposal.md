# The generate dialog shows what the chosen template fills in

## Why

DqDocumentGenereren (coordinator decision 10) draws the Verdagingsbrief chosen, with "Wat het sjabloon invult" listing Aan, Zaak, Beslistermijn "1 november 2026" and Verdaging "2 weken, tot 15 november 2026". The dialog showed a fixed To and Case only, whatever template was chosen, so the info note sat 53px higher than drawn (r32 verdict).

## What changes

- A library template may name its fills: `fills: [{ label, field, as, from? }]`, read off the case. `as: date` writes the date out in full; `as: extension` the period (whole weeks as weeks) and the day it ends, counted from `from`.
- TemplateLibraryService lists each template's `fills`; the generate dialog shows the chosen template's fills under To and Case.
- Verdagingsbrief names the decision deadline (`legalDecisionDate`) and the adjournment (`statutoryExtension` from `legalDecisionDate`). Templates are configuration: no template id appears in code.
