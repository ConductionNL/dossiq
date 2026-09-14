# Tasks: handing-a-case-over

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 36, candidates
C-case-core-44 (matrix hole), C-case-core-46 (matrix hole), C-case-core-34
and C-access-and-privacy-56. Statutory: Awb 2:3. Decision D6 admits all
three `must` candidates, one of them on documented passers only. Depends
on cluster 11, carried by dossiq `case-grants-name-their-source` (wave 1)
over openregister `permission-provenance-and-deny` and
`rbac-inherits-to-children`. The offboarding signal waits on humaniq.

- [ ] 1.1 Extend `CaseTransferService` to an internal counterparty: a team
  in place of the target organisation, writing the same `casetransfer`
  record (D-1).
  - `tests/unit/Service/InternalCaseHandoverTest.php`
  - `@spec openspec/changes/handing-a-case-over/specs/case-management/spec.md`
- [ ] 1.2 Keep the number, the history, the documents and the running
  terms; refuse a handover to an unresolvable team (D-1, D-2).
  - `tests/unit/Service/CaseTransferServiceTest.php`
- [ ] 1.3 Refusal back by the receiving team, on the same custody trail,
  with an outstanding handover visible to the sender (D-2).
- [ ] 2.1 Declare whether a handover is a doorzending; tell the applicant
  where the case went, through the declared moments, and say nothing on an
  internal move (D-3).
  - `tests/unit/Service/DoorzendingNotificationTest.php`
- [ ] 3.1 The coordinator as a role binding on the case, beside `assignee`
  as the handler, on the people panel and in search (D-4).
  - `tests/unit/Service/CaseCoordinatorSeatTest.php`
  - `tests/vitest/peopleOnTheCaseSeats.spec.js`
- [ ] 3.2 `caseType`: require a coordinator before signing, refusing with
  the rule named per ADR-050 (D-5).
  - `tests/unit/Service/CoordinatorRequiredBeforeSigningTest.php`
- [ ] 3.3 A handover empties a seat whose holder is not in the receiving
  team, visibly and on the record (D-4).
- [ ] 4.1 `case`: declare an external home, the application, the
  identifier there and the link (D-6).
  - `tests/unit/Service/ExternallyHomedCaseTest.php`
- [ ] 4.2 The central list carries both kinds; the lifecycle acts that
  perform work are disabled on an externally homed case and say where the
  work is (D-6).
  - `tests/vitest/externallyHomedCase.spec.js`
- [ ] 4.3 Name integriq `zgw-connectors-for-dossiq` and openregister
  `objecten-api-facade` as the halves that keep the two in step (D-6).
- [ ] 5.1 The leaver handover: one act over cases as handler, cases as
  coordinator, open tasks and drafts, previewed before it runs (D-7).
  - `tests/unit/Service/LeaverHandoverTest.php`
- [ ] 5.2 Record who ran it, when, what moved and from whom, readable on
  each moved case (D-7).
- [ ] 5.3 Listen for a humaniq offboarding signal when one exists; until
  then an administrator names the person, and the proposal records the
  missing change (D-7).
- [ ] 6.1 Dutch and English strings.
- [ ] 6.2 `tests/e2e/handing-a-case-over.spec.ts`: a case handed to
  another team keeping its number and terms, a refusal back on the custody
  trail, a doorzending that tells the applicant and an internal move that
  does not, two seats on one case, a besluit refused for an empty
  coordinator seat, an externally homed case in the same list with its
  work acts disabled, and a leaver handover previewed then run;
  `openspec validate handing-a-case-over --strict`.
