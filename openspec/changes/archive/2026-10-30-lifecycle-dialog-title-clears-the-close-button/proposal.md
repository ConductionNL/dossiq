---
kind: code
depends_on: []
---

# Proposal: lifecycle-dialog-title-clears-the-close-button

## Why

On the review instance (10 Oct, finding F6) the dialog behind Meer > Levensloop
opened with its title "Wat je met deze zaak kunt doen" running under the close
button. NcDialog's default size is small (about 390px), its title is centred over
the full width, and it reserves no room for the close button, so a title of that
length reaches the X.

## What Changes

- `CaseLifecycleMenuDialog` opens with `size="normal"`. The title fits on one line
  clear of the close button, and the acts with their reasons wrap less.

## Impact

- `src/dialogs/CaseLifecycleMenuDialog.vue`.
- Test: `tests/vitest/caseTransitionOutcome.spec.js`.
- No backend change.
