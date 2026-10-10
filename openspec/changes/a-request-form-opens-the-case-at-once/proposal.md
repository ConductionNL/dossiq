---
kind: code
depends_on: [intake-says-when-the-term-starts]
---

# Proposal: a-request-form-opens-the-case-at-once

dossiq's half of decision 179 (Ruben, 10 October 2026), answered as Q-dossiq-L1-4: "We dont intake to an intake, we intake into a case, or ticket or something else." Cross-app change: `hydra/openspec/changes/form-submits-into-its-destination-object`, architecture in hydra ADR-117. Needs `openregister/form-destination-validator`.

## Why

`intake-says-when-the-term-starts` wants the screen right after sending to name the reference, the received moment, the term start and the deadline (REQ-TERM-041, D-5). Task 2.1 stayed open because portaliq queues the request and dossiq opens the case a minute later. At the moment the confirmation renders, there is no case and no term.

Three more gaps sit on the same path:

- `receivedAt` and `termStartsAt` are written by `IntakeTermStartListener` in a second save after the create. A caller reading its own save result does not see them.
- `deadline` counts from `startDate`, not from `termStartsAt`, so a Sunday request shows a deadline one day before the term it states.
- The portal's bezwaar and klacht actions write a `portaalVerzoek`. No code turns it into a case, although REQ-POR-008 says one is opened.

## What changes

1. **Term fields on create.** `IntakeTermStart` moves from the post-create listener to an `ObjectCreatingEvent` listener, so `receivedAt`, `termStartsAt` and `receivedOutsideWorkingHours` are part of the create.
2. **Deadline counts from the term start.** For a case opened by a submit, `startDate` is the date of `termStartsAt` (question Q11, recommended option).
3. **Confirmation markers.** `case.identifier`, `receivedAt`, `termStartsAt` and `deadline` carry `x-openregister.confirmation: true`; `identifier`, `receivedAt`, `termStartsAt` and `receivedOutsideWorkingHours` carry `x-openregister.serverSet: true`.
4. **`portaalVerzoek` retired.** The bezwaar and klacht actions submit into a case of the bezwaar or klacht case type, with the decision as cross-reference (Q8, recommended option). Existing `portaalVerzoek` objects are drained into cases or reported.
5. **Case types are valid destinations.** Publishing a case type re-checks the forms bound to it (`caseType.intakeFormRef`, portaliq bindings) through OpenRegister's validator, including `intakeRequirements.requiredBeforeCreation`.
6. **Woo request and Nextcloud Forms intake** answer with the same confirmation fields: `WooRequestIntake::writeCase` returns `receivedAt` and `termStartsAt`; `FormsIntakeService` maps answers to typed properties through a binding checked at bind time (Q6, recommended option).
7. **D-5 amended** in `intake-says-when-the-term-starts/design.md`: the screen reads all four values from the submit response.

## Rollback

Steps 1 to 3 are additive. Step 4 keeps the `portaalVerzoek` schema until its drain reports zero.
