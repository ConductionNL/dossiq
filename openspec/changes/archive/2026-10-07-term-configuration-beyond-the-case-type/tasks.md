# Tasks: term-configuration-beyond-the-case-type

Tier: V1. Kind: code. Size M. Rows 8.24, 8.29 and 8.30. Depends on
`termijnbewaking-op-engine-timers` for the timer, the suspension and the
ladder.

- [x] 1.1 `lib/Settings/register.d/60-termijnbewaking.json`: a first-response
  term per case type as an ordinary `deadlineDefinition` (D-1).
  - `@spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md`
- [x] 1.2 Record the outcome on the case: met or missed, and the size of the
  overrun in the term's own counting mode (D-1, D-2).
  - `tests/Unit/Service/Term/FirstResponseOutcomeTest.php`
- [x] 1.3 `lib/Service/ComplaintService.php`: take the acknowledgement term
  from the declaration instead of its private constant, with the same dates
  as today for the complaint case type.
  - `tests/Unit/Service/ComplaintServiceTest.php`
- [x] 1.4 Report the overrun: how many were missed and by how much, per case
  type and per organisation.
- [x] 2.1 Declare a term per organisation, per service and per priority, with
  the resolution order and the case-type fallback (D-3).
  - `tests/Unit/Service/Term/TermResolutionTest.php`
- [x] 2.2 Record the resolution that produced a case's term on the case
  (D-4).
  - `tests/Unit/Service/Term/TermResolutionTest.php` (the snapshot case)
- [x] 2.3 Read the priority from `case-priority-impact-urgency` rather than a
  second priority field.
- [x] 3.1 `deadlineDefinition.runsInStatuses`: the statuses the clock runs in
  (D-5).
  - `tests/Unit/Service/Term/TermStatusClockTest.php`
- [x] 3.2 Entering a status outside the set suspends the engine timer and
  leaving it resumes, through the existing suspend and resume path (D-5).
  - `tests/Unit/Service/Term/TermStatusClockTest.php`
- [x] 4.1 A threshold may be a share of the term as well as a number of days,
  resolved to a date when the timer is armed (D-6, D-7).
  - `tests/Unit/Service/Term/ThresholdSharesTest.php`
- [x] 4.2 Extending a term re-arms the timer and resolves the shares again
  (D-6).
- [x] 5.1 Dutch and English strings for the first-response term, the overrun,
  the resolution explanation and the threshold shares.
- [x] 5.2 `tests/e2e/term-configuration-beyond-the-case-type.spec.ts`: a
  missed first response with its overrun, one case type with two municipal
  norms, a clock stopped by a status, a percentage threshold on two term
  lengths;
  `openspec validate term-configuration-beyond-the-case-type --type change --strict`.
