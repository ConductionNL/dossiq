# Proposal: obligations-placed-at-intake

## Why

Decision 271 (30 Oct 2026): when a form needs a second person to sign, the submit creates the case at once, in a state that waits for that person, and the case app holds that state. There is no portal queue (decisions 179 and 181). Portaliq's resident-identity-in-forms T04 builds the form side: the form names the second signer, the submit opens the case and the portal mails that person a link. The case app has to hold the wait, accept the signature, and apply its own rule when nobody signs.

Dossiq already has one generic way for a case to wait on somebody: the obligation (what-a-transition-declares). It blocks what its kind declares, and meeting it releases the case. A second signature is one more obligation, placed by the form instead of by a handler. Nothing here names a procedure (decision 182): the kind, its title, what it blocks and what happens when it is not met are case type configuration.

## What changes

- The case schema gains `intakeObligations`: the submit writes what the case waits on (kind, title, the person's name and address, due date, state `awaiting`).
- When a case is received, dossiq places an obligation for each awaiting entry, with the kind's declared blocks (closing when the case type does not declare the kind).
- `POST /api/portal/obligations/settle` takes portaliq's signed assertion and `case`, `kind`, `answer` (`sign` or `refuse`). Signing meets the obligation and records the portal subject and level of assurance. The applicant cannot sign for their own case.
- A case type's obligation kind gains `whenUnmet` (`stay` or `withdraw`), applied on a refusal and by a daily job when the due date passes.
- dossiq writes `signed`, `refused` or `expired` back on the case's entry, so the portal can tell the applicant what the case waits on.
- Register 0.20.86 to 0.20.87 (case 1.54.0, caseType 1.45.0, obligation 1.1.0).

## Out of scope

- The mail to the applicant on a refusal or a lapse: a case type sends it through its flows on the case update.
- Moving the case to another status on a lapse: a handler or a flow does that once the obligation is withdrawn.
