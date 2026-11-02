# bezwaar-advisory-committee Delta: creating-into-deliberation-runs-the-panel-check

**Status**: draft
**Scope**: every single-object save or create of an advice request that starts deliberation, changes the panel or objection during deliberation, or reaches a post-deliberation status.

## MODIFIED Requirements

### Requirement: every single-object save that starts deliberation runs the panel check

The panel check of REQ-BAC-2 MUST run on every single-object save that moves an advice request into `in-deliberation`, not only on `transitionAdviceStatus()`. That includes a lifecycle action on the advice request page and a direct OpenRegister API write. It also includes a single-object create of an advice request whose status is `in-deliberation`. The check MUST also run again when a save keeps the status at `in-deliberation` but changes the panel or the objection. The objection is `bezwaar`, or the legacy `objectionProceeding` when `bezwaar` is empty, so the objection backfill does not count as a change. The check MUST read the panel and the objection from the request as it will be saved. A request without an objection MUST count as unverifiable, never as independent. So MUST a contested decision that has no recorded author (no owner, `createdBy` or `steller`). Saves that fire no `ObjectUpdatingEvent` or `ObjectCreatingEvent` (bulk save, import, flow writes) are outside this requirement. The check MUST run before OpenRegister saves the write. A refused check MUST stop the write. An updated request MUST keep its old values, and a created request MUST NOT be saved. The refusal MUST reach the caller as the write's error. The trail entry of the refusal MUST be written before the write is stopped, exactly as `transitionAdviceStatus()` writes it. On a refused create no record exists to carry it, so the full entry MUST go to the error log. A check that fails with an unexpected error MUST stop the write too. A save that leaves the status, the panel and the objection of a request in deliberation as they were MUST NOT run the check, and neither may a move anywhere else than `in-deliberation`. A create in any other status MUST NOT run the check.

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

#### Scenario: a create in deliberation without an objection is refused
@e2e exclude covered by DeliberationStartGuardTest and PanelIndependenceCheckerGoldenTest
- **WHEN** a client creates an advice request with `status: in-deliberation`, a panel and an empty `bezwaar`
- **THEN** OpenRegister refuses the create as unverifiable
- **AND** no advice request is saved

#### Scenario: a contested decision without a recorded author is unverifiable
@e2e exclude covered by PanelIndependenceCheckerGoldenTest
- **GIVEN** a contested primair besluit with no owner, `createdBy` or `steller`
- **WHEN** the panel check runs for an advice request on it
- **THEN** the verdict is unverifiable, with no conflicting member
- **AND** a blank panel member is not matched against the missing author

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

#### Scenario: a panel change during deliberation runs the check again
@e2e exclude needs a contested decision signed by a panel member on a seeded instance; covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `in-deliberation` with an independent panel
- **WHEN** a client replaces the panel with one that holds the author of the contested decision
- **THEN** OpenRegister refuses the write with the conflict reason
- **AND** the request keeps its old panel

#### Scenario: an objection change during deliberation runs the check again
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `in-deliberation`
- **WHEN** a client points `bezwaar` at another objection, or empties it
- **THEN** the panel check runs on the new objection
- **AND** a refusal, or a check that fails, stops the write

#### Scenario: other edits during deliberation are not checked
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `in-deliberation`
- **WHEN** a handler edits its advice text, or the objection backfill copies `objectionProceeding` into `bezwaar`
- **THEN** the panel check does not run and the write is saved

## ADDED Requirements

### Requirement: an advice request reaches advice-issued or inadmissible only from in-deliberation

A single-object save MUST NOT move an advice request into `advice-issued` or `inadmissible` from any status other than `in-deliberation`. A create MUST NOT start an advice request in either status. Such a write MUST be refused before OpenRegister saves it, with error code `deliberation-skip-refused`, and the request MUST keep its old status. A save that keeps a request in `advice-issued` or `inadmissible`, or moves it between those two, is not a skip and MUST NOT be refused by this requirement. The move from `in-deliberation` through `transitionAdviceStatus()` MUST keep working. Saves that fire no `ObjectUpdatingEvent` or `ObjectCreatingEvent` are outside this requirement.

#### Scenario: a raw write skips deliberation
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `assigned`
- **WHEN** a client writes `status: advice-issued` on it through the OpenRegister API
- **THEN** OpenRegister refuses the write with "Advice can only be issued after deliberation"
- **AND** the request stays in `assigned`

#### Scenario: a create skips deliberation
@e2e exclude covered by DeliberationStartGuardTest
- **WHEN** a client creates an advice request with `status: advice-issued` or `status: inadmissible`
- **THEN** OpenRegister refuses the create
- **AND** no advice request is saved

#### Scenario: the committee issues its advice after deliberation
@e2e exclude covered by DeliberationStartGuardTest
- **GIVEN** an advice request in `in-deliberation` with the required advice fields
- **WHEN** `transitionAdviceStatus()` moves it to `advice-issued`
- **THEN** the write is saved
