---
kind: code
depends_on: []
---

# Proposal: a-recorded-decision-is-listed-on-its-case

## Why

Rehearsal R2 (15 Oct, Woo case): after the handler recorded the decision (Meer > Woo-besluit), the case page's next-step checklist kept "Leg het besluit vast" on "Nog doen". The checklist item reads the case's `decisions` list; the decision row named its case, but nothing added it to the case's list.

## What Changes

- Recording a decision from the case's assessments adds the decision to the case's `decisions`, once, keeping what was listed before (`CaseDecisionLink`, generic: it knows a case and a decision, nothing of the procedure).
- A case that cannot be updated costs the checklist tick, not the decision; it is logged.

## Impact

- `lib/Service/DecisionDocument/CaseDecisionLink.php` (new), `lib/Service/DecisionDocument/PublicationReadiness.php`, `lib/Service/WOODecisionService.php`.
- Decisions recorded before this change are not back-filled.
