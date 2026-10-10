# Design: a-request-form-opens-the-case-at-once

## D-1. The term is stamped inside the create

`IntakeTermStartListener` listens to `ObjectCreatingEvent` instead of `ObjectCreatedEvent`. It sets the three fields on the object before it is saved. One save, one audit entry, and the create result carries the fields. `IntakeTermStart::firstWorkingMomentAtOrAfter` is unchanged.

## D-2. `startDate` follows the term start for a submitted case

A case opened through a form submit gets `startDate` = the date of `termStartsAt`. A case opened by a handler keeps the date the handler gives. The `deadline` expression (`dateAdd(startDate, processingDeadline)`) stays as it is, so there is one deadline rule.

## D-3. bezwaar and klacht are cases from the first request

The portal actions `createBezwaar` and `createKlacht` change destination from `portaalVerzoek` to `case`, with `caseType` fixed per action and `tegenZaak` and `tegenBesluit` as cross-references. `withinTerm` is computed by the bezwaar case type's timeliness rule, already on the case, instead of on the request object. Attachments become case documents.

## D-4. Drain

`occ dossiq:intake:drain-portaalverzoek` turns every `portaalVerzoek` into a case through `FormSubmitService`, copies the old id to `externalReference`, and reports refused ones with findings. The schema is removed when the report shows zero pending.

## Amendment to intake-says-when-the-term-starts, D-5

D-5 now reads: the screen shows the four values the submit response returns, because the case exists before the response does. The decision behind it is Q-dossiq-L1-4, answered by decision 179. The option "say that start and deadline follow later" is no longer needed.
