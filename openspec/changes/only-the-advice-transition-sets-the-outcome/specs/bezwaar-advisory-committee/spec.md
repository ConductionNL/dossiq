# bezwaar-advisory-committee Delta: only-the-advice-transition-sets-the-outcome

**Status**: draft
**Scope**: every single-object save that moves an advice request into `advice-issued` or `inadmissible`.

## ADDED Requirements

### Requirement: only the advice transition writes an advice request into advice-issued or inadmissible

A save that moves an advice request into `advice-issued` or `inadmissible` MUST come from `AdvisoryCommitteeService::transitionAdviceStatus()`. Any other such save MUST be refused before OpenRegister saves it, with error code `deliberation-outcome-refused`. That covers a raw API write, and a bulk save, import or flow write once those fire the save events. The transition MUST mark its own save with an in-process marker: a depth counter on a shared service instance, never a value from the request. A save that keeps a request in `advice-issued` or `inadmissible` is not a move into it, and this requirement MUST NOT refuse it.

#### Scenario: a raw write issues the advice
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `in-deliberation`
- **WHEN** a client writes `status: advice-issued` on it through the OpenRegister API
- **THEN** OpenRegister refuses the write with error code `deliberation-outcome-refused`
- **AND** the request stays in `in-deliberation`

#### Scenario: a raw write declares the request inadmissible
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `in-deliberation`
- **WHEN** a client writes `status: inadmissible` on it through the OpenRegister API
- **THEN** OpenRegister refuses the write
- **AND** the request stays in `in-deliberation`

#### Scenario: the committee issues its advice
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `in-deliberation` with the required advice fields
- **WHEN** `transitionAdviceStatus()` moves it to `advice-issued`
- **THEN** the write is saved

#### Scenario: an edit after the advice
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `advice-issued`
- **WHEN** a client edits its advice text without changing the status
- **THEN** the write is saved
