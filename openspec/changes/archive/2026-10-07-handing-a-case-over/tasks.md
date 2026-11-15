# Tasks: handing-a-case-over

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 36, candidates
C-case-core-44 (matrix hole), C-case-core-46 (matrix hole), C-case-core-34
and C-access-and-privacy-56. Statutory: Awb 2:3. Decision D6 admits all
three `must` candidates, one of them on documented passers only. Depends
on cluster 11, carried by dossiq `case-grants-name-their-source` (wave 1)
over openregister `permission-provenance-and-deny` and
`rbac-inherits-to-children`. The offboarding signal waits on humaniq.

**Where the tests actually live.** Every path below reads
`tests/unit/Service/…` and the suite is `tests/Unit/Service/…`, capital U.
The files are at the capitalised path; the lower-case one is a typo in this
plan and not a second location.

- [x] 1.1 Extend `CaseTransferService` to an internal counterparty: a team
  in place of the target organisation, writing the same `casetransfer`
  record (D-1).
  - `tests/Unit/Service/InternalCaseHandoverTest.php`
  - `@spec openspec/changes/handing-a-case-over/specs/case-management/spec.md`
  - The act enters through `CaseTransferService::handToTeam()` so there is
    one door for every case move; the work is in
    `lib/Service/Transfer/InternalHandover.php` because the entry point was
    already at its complexity ceiling.
- [x] 1.2 Keep the number, the history, the documents and the running
  terms; refuse a handover to an unresolvable team (D-1, D-2).
  - `tests/Unit/Service/InternalCaseHandoverTest.php`, the tests
    `testAHandoverMovesTheCaseAndKeepsItsIdentity` and
    `testAnUnresolvableTeamRefusesTheHandover`. No separate
    `CaseTransferServiceTest.php`: this is one behaviour of one service and
    a second file over the same collaborators would be a second place to
    look, not a second verdict. `CaseTransferServiceFederationTest.php`
    keeps the federated half.
- [x] 1.3 Refusal back by the receiving team, on the same custody trail,
  with an outstanding handover visible to the sender (D-2).
  - The case moves when it is handed and the handover stays outstanding
    until somebody picks it up, which are two facts kept apart:
    `assignedGroup` changes at once, `handoverPending` keeps the case on
    the sending team's lens. `outstandingFor()` reads the transfer records
    rather than the sender's cases, because the case has already moved.
- [x] 2.1 Declare whether a handover is a doorzending; tell the applicant
  where the case went, through the declared moments, and say nothing on an
  internal move (D-3).
  - `tests/Unit/Service/DoorzendingNotificationTest.php`
  - The announcement happens at the HAND, not at the accept, because the
    hand is when the case actually moves.
- [x] 3.1 The coordinator as a role binding on the case, beside `assignee`
  as the handler, on the people panel and in search (D-4).
  - `tests/Unit/Service/CaseCoordinatorSeatTest.php`
  - `tests/vitest/peopleOnTheCaseSeats.spec.js`
  - Search is `CaseSeats::casesCoordinatedBy()` over the role records, and
    `GET /api/case/{caseId}/seats` answers the pair. The People tab shows
    the handler in Seats and the coordinator in Parties with its role type;
    a second copy of the coordinator in Seats was deliberately not added.
- [x] 3.2 `caseType`: require a coordinator before signing, refusing with
  the rule named per ADR-050 (D-5).
  - `tests/Unit/Service/CoordinatorRequiredBeforeSigningTest.php`
  - The check runs in `BeschikkingService::onderteken()` BEFORE the TSP is
    called: refusing afterwards would leave a signed document behind a
    refused act.
- [x] 3.3 A handover empties a seat whose holder is not in the receiving
  team, visibly and on the record (D-4).
  - `CaseSeatReconciler`, recorded as `casetransfer.emptiedSeats` beside
    the reason. A seat whose holder IS in the receiving team survives.
- [x] 4.1 `case`: declare an external home, the application, the
  identifier there and the link (D-6).
  - `tests/Unit/Service/ExternallyHomedCaseTest.php`
- [x] 4.2 The central list carries both kinds; the lifecycle acts that
  perform work are disabled on an externally homed case and say where the
  work is (D-6).
  - `tests/vitest/externallyHomedCase.spec.js`
  - `CaseActionProvider::availableActions()` publishes them blocked, and
    `execute()` REFUSES the same move: a blocked flag nothing checks on the
    write path is a suggestion, and the first client that ignores it moves
    a status the specialist application never hears about.
- [x] 4.3 Name integriq `zgw-connectors-for-dossiq` and openregister
  `objecten-api-facade` as the halves that keep the two in step (D-6).
  - Named in the class docblock of `lib/Service/Cases/ExternalHome.php` and
    in the PR body. dossiq declares and builds neither.
- [x] 5.1 The leaver handover: one act over cases as handler, cases as
  coordinator, open tasks and drafts, previewed before it runs (D-7).
  - `tests/Unit/Service/LeaverHandoverTest.php`
  - ⚠️ **Drafts are named, not moved, and that is a gap rather than a
    decision.** A draft is private to its author because OpenRegister owns
    the object and its owner, and dossiq has no seam to change that owner:
    `ObjectService` offers a save, not a transfer of ownership. The drafts
    a leaver holds are listed in the preview and in the result; re-owning
    them waits on an OpenRegister change nothing has opened yet.
- [x] 5.2 Record who ran it, when, what moved and from whom, readable on
  each moved case (D-7).
  - `case.handoverRecord`, written per moved case and per moved seat.
- [x] 5.3 Listen for a humaniq offboarding signal when one exists; until
  then an administrator names the person, and the proposal records the
  missing change (D-7).
  - No listener, because there is no signal to listen for: the register
    names no humaniq slug carrying one, and a listener on an event nobody
    emits is dead code that reads as a finished integration. The
    administrator path is `POST /api/leaver-handover/{preview,execute}`,
    and the missing humaniq change is named in the proposal and the PR body.
- [x] 6.1 Dutch and English strings.
  - Thirteen keys, checked by `node tests/l10n/check-l10n.js` rather than
    by eye.
- [x] 6.2 `tests/e2e/handing-a-case-over.spec.ts`: a case handed to
  another team keeping its number and terms, a refusal back on the custody
  trail, a doorzending that tells the applicant and an internal move that
  does not, two seats on one case, a besluit refused for an empty
  coordinator seat, an externally homed case in the same list with its
  work acts disabled, and a leaver handover previewed then run;
  `openspec validate handing-a-case-over --strict`.
  - Written and tagged, NOT run: no Playwright in this lane.
  - Two of the eight scenarios are deliberately NOT driven from the
    browser, and the file says so at the top rather than leaving a green
    run to imply them. **The besluit refused for an empty coordinator
    seat** needs a case type declaring the requirement, which means
    publishing one on a shared instance; it is asserted in
    `tests/Unit/Service/CoordinatorRequiredBeforeSigningTest.php`. **The
    leaver handover RUN** would reassign every case every other session
    seeded on this instance, which is the e2e-residue failure this suite
    has already paid for once; the walk, the seats and the record are
    asserted in `tests/Unit/Service/LeaverHandoverTest.php`.
