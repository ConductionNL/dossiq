# Design: a-request-form-opens-the-case-at-once

## D-1. The term is stamped inside the create

`IntakeTermStartListener` sets the three fields on the case before it is saved. It listens to `ObjectCreatingEvent`, and to the update that moves a case out of OpenRegister status `draft` (decision 180). A draft is skipped. One save, one audit entry, and the create result carries the fields.

There is one listener. `receipt-and-start-dates-are-set-at-creation` (D-7) moved the same listener before the store for its own reason, the UTC day. This change builds on that listener and adds two things: it skips a draft, and it handles the save that sends one. Both registrations use `IntakeListenerRegistrar::RECEIPT_DATES_PRIORITY`, above OpenRegister's calculations. The `ObjectCreatedEvent` registration stays as the fallback for the bulk save, and it skips a draft too.

## D-2. `startDate` follows the term start for a submitted case

`CaseReceiptDates` writes `startDate` when the case carries none. It is the receipt day, and when the term starts on a later day it is the day the term starts (decision 181, Q11). A request sent on Sunday evening starts on Monday. A start date the creating path supplied is kept. The `receiptDate` answer stays the day the request came in. The `deadline` expression (`dateAdd(startDate, processingDeadline)`) stays as it is, so there is one deadline rule.

The rule is one rule for every case, not one for submits and one for handlers. The create form no longer asks a start date (REQ-TERM-042), so "the handler gave one" no longer tells the two apart. REQ-TERM-042 is amended in this change.

A draft that is sent gets its start date written again. The schema's own `startDate` calculation fills the day the draft was begun, and nobody gave that date.

## D-3. bezwaar and klacht are cases from the first request

The portal actions `createBezwaar` and `createKlacht` change destination from `portaalVerzoek` to `case`, with `caseType` stamped per action from one app setting, `portal_filing_case_types`, a JSON object that maps an action id to a case type identifier or uuid (a procedure is configuration, decision 182). Unset, it maps `createBezwaar` to the shipped `bezwaar` case type and `createKlacht` to `klacht-behandeling`. An action whose case type is unknown is not offered and `contestedCase` and `contestedDecision` as cross-references on the case. `withinTerm` is computed by the bezwaar case type's timeliness rule, already on the case, instead of on the request object. Attachments become case documents.

## D-3b. A draft case is not received

A saved form is a case in status `draft`. Receipt-time listeners (term start, acknowledgement, routing) skip a draft and run on the move out of `draft`, which is the moment of receipt. `IntakeRequirementsListener` checks `requiredBeforeCreation` on that move too, not on the draft save, because a draft may miss required data.

## D-4. Drain

`occ dossiq:intake:drain-portaalverzoek` turns every `portaalVerzoek` into a case through `FormSubmitService`, copies the old id to `externalReference`, and reports refused ones with findings. The schema is removed when the report shows zero pending.

## Amendment to intake-says-when-the-term-starts, D-5

D-5 now reads: the screen shows the four values the submit response returns, because the case exists before the response does. The decision behind it is Q-dossiq-L1-4, answered by decision 179. The option "say that start and deadline follow later" is no longer needed.
