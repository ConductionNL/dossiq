# bezwaar-advisory-committee Delta: creating-into-deliberation-runs-the-panel-check

**Status**: draft
**Scope**: a single-object create of an advice request directly in `in-deliberation`.

## MODIFIED Requirements

### Requirement: every single-object save that starts deliberation runs the panel check

The panel check of REQ-BAC-2 MUST run on every single-object save that moves an advice request into `in-deliberation`, not only on `transitionAdviceStatus()`. That includes a lifecycle action on the advice request page and a direct OpenRegister API write. It also includes a single-object create of an advice request whose status is `in-deliberation`. The check MUST read the panel and the objection from the request as it will be saved. Saves that fire no `ObjectUpdatingEvent` or `ObjectCreatingEvent` (bulk save, import, flow writes) are outside this requirement. The check MUST run before OpenRegister saves the write. A refused check MUST stop the write. An updated request MUST keep its old status, and a created request MUST NOT be saved. The refusal MUST reach the caller as the write's error. The trail entry of the refusal MUST be written before the write is stopped, exactly as `transitionAdviceStatus()` writes it. On a refused create no record exists to carry it, so the full entry MUST go to the error log. A check that fails with an unexpected error MUST stop the write too. A write that leaves the status at `in-deliberation`, or moves it anywhere else, MUST NOT run the check. A create in any other status MUST NOT run the check.

#### Scenario: a client creates an advice request in deliberation with a conflicted panel
@e2e exclude needs a contested decision signed by a panel member on a seeded instance; covered by DeliberationStartGuardTest
- **GIVEN** a contested primair besluit decided by `p.janssen`
- **WHEN** a client creates an advice request with `status: in-deliberation` and panel `["a.bakker", "p.janssen"]` through the OpenRegister API
- **THEN** OpenRegister refuses the create with the conflict reason
- **AND** no advice request is saved
- **AND** the `independence-check-failed` entry naming `p.janssen` is logged in full

#### Scenario: a client creates an advice request in deliberation when independence cannot be verified
@e2e exclude needs a broken object store; covered by DeliberationStartGuardTest
- **GIVEN** a contested primair besluit that cannot be read
- **WHEN** a client creates an advice request with `status: in-deliberation` and a panel
- **THEN** OpenRegister refuses the create
- **AND** no advice request is saved
- **AND** the `independence-check-unverifiable` entry is logged in full

#### Scenario: a create in deliberation without a panel, or with a failing check, is refused
@e2e exclude needs a failing checker; covered by DeliberationStartGuardTest
- **WHEN** a client creates an advice request with `status: in-deliberation` and no panel
- **THEN** OpenRegister refuses the create with "Panel must be set before deliberation can start"
- **WHEN** the panel check throws an unexpected error on such a create
- **THEN** OpenRegister refuses the create
- **AND** no advice request is saved

#### Scenario: a create in another status is not checked
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** a panel member who decided the contested primair besluit
- **WHEN** a client creates an advice request in `assigned` with that member on the panel
- **THEN** the panel check does not run and the request is saved
