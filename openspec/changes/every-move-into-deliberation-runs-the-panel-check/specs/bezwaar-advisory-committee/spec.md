# bezwaar-advisory-committee Delta: every-move-into-deliberation-runs-the-panel-check

**Status**: draft
**Scope**: every write that moves an advice request into `in-deliberation`.

## ADDED Requirements

### Requirement: every write that starts deliberation runs the panel check

The panel check of REQ-BAC-2 MUST run on every write that moves an advice request into `in-deliberation`, not only on `transitionAdviceStatus()`. That includes a lifecycle action on the advice request page and a direct OpenRegister API write. The check MUST run before OpenRegister saves the write. A refused check MUST stop the write, and the request MUST keep its old status. The refusal MUST reach the caller as the write's error. The trail entry of the refusal MUST be written before the write is stopped, exactly as `transitionAdviceStatus()` writes it. A check that fails with an unexpected error MUST stop the write too. A write that leaves the status at `in-deliberation`, or moves it anywhere else, MUST NOT run the check.

#### Scenario: a lifecycle action starts deliberation with a conflicted panel
@e2e exclude needs a contested decision signed by a panel member on a seeded instance; covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `assigned` with panel `["a.bakker", "p.janssen"]`
- **AND** `p.janssen` decided the contested primair besluit
- **WHEN** a handler moves the request to `in-deliberation` with the page's lifecycle action
- **THEN** OpenRegister refuses the write with reason "Lid was betrokken bij het bestreden besluit (Awb Art. 7:13 lid 3)"
- **AND** the request stays in `assigned`
- **AND** the request's trail holds an `independence-check-failed` entry naming `p.janssen`

#### Scenario: an API write starts deliberation when independence cannot be verified
@e2e exclude needs a broken object store; covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `assigned` with a panel
- **AND** the contested primair besluit cannot be read
- **WHEN** a client writes `status: in-deliberation` on the request through the OpenRegister API
- **THEN** OpenRegister refuses the write
- **AND** the request stays in `assigned`
- **AND** the request's trail holds an `independence-check-unverifiable` entry with no conflicting member

#### Scenario: an independent panel starts deliberating
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `assigned` with a panel that took no part in the contested decision
- **WHEN** a handler moves the request to `in-deliberation`
- **THEN** the write is saved

#### Scenario: a request without a panel does not start deliberating
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `assigned` without a panel
- **WHEN** a handler moves the request to `in-deliberation`
- **THEN** OpenRegister refuses the write with "Panel must be set before deliberation can start"

#### Scenario: the check fails with an unexpected error
@e2e exclude needs a failing checker; covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `assigned` with a panel
- **AND** the panel check throws an unexpected error
- **WHEN** a handler moves the request to `in-deliberation`
- **THEN** OpenRegister refuses the write
- **AND** the request stays in `assigned`

#### Scenario: other writes are not checked
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request already in `in-deliberation`
- **WHEN** a handler edits its advice text
- **THEN** the panel check does not run and the write is saved
