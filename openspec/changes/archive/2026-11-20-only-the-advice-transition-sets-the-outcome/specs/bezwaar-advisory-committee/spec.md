# bezwaar-advisory-committee Delta: only-the-advice-transition-sets-the-outcome

**Status**: draft
**Scope**: every single-object save that moves an advice request into `advice-issued` or `inadmissible`, and the endpoint the advice request page uses to move it.

## ADDED Requirements

### Requirement: only the advice transition writes an advice request into advice-issued or inadmissible

A save that moves an advice request into `advice-issued` or `inadmissible` MUST come from `AdvisoryCommitteeService::transitionAdviceStatus()`. Any other such save MUST be refused before OpenRegister saves it, with error code `deliberation-outcome-refused`. That covers a raw API write, and a bulk save, import or flow write once those fire the save events. The transition MUST mark its own save with an in-process marker: a depth counter per advice request on a shared service instance, never a value from the request. A save of another advice request during the transition MUST NOT count as marked. A save that keeps a request in `advice-issued` or `inadmissible` is not a move into it, and this requirement MUST NOT refuse it.

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

### Requirement: the committee moves an advice request through its own endpoint

`POST /api/bezwaar/advice-requests/{id}/transition` with body `{"status": "<target>"}` and any advice fields MUST run `transitionAdviceStatus()` and answer the updated advice request as OpenRegister serialises an object, `@self` included. Only a member of the `bezwaarcommissie` group or an administrator MAY call it. The case's handlers MUST NOT, because Awb art. 7:13 keeps the advisory committee independent of the officials who handled the case. An unauthenticated call MUST get 401, and a refused caller 403 before anything is read or written. A target the lifecycle does not allow, or missing advice fields, MUST get 400 with the reason. A request that does not exist MUST get 404.

#### Scenario: the committee issues the advice through the endpoint
@e2e exclude needs a seeded committee member; covered by BezwaarAdviceRequestControllerTest
- **GIVEN** an advice request in `in-deliberation` and a member of `bezwaarcommissie`
- **WHEN** the member posts `{"status": "advice-issued", "conclusion": "gegrond", "recommendation": "herroepen"}`
- **THEN** the response is 200 with the updated advice request, `@self` included

#### Scenario: a case handler cannot issue the advice
@e2e exclude covered by BezwaarAdviceRequestControllerTest
- **GIVEN** a handler of the bezwaar case who is not in `bezwaarcommissie`
- **WHEN** the handler posts a transition
- **THEN** the response is 403 and the advice request is unchanged

#### Scenario: a forbidden move through the endpoint
@e2e exclude covered by BezwaarAdviceRequestControllerTest
- **GIVEN** an advice request in `assigned`
- **WHEN** a committee member posts `{"status": "advice-issued"}`
- **THEN** the response is 400 with the reason
