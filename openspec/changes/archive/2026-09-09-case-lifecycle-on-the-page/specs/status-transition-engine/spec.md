## MODIFIED Requirements

### Requirement: Available Transitions for Current User

You see, on the case page, only the transitions you may take from the case's
current status. The system SHALL compute the available transitions from the
case type's workflow template, the current status, the signed-in user's role
and guard satisfaction, and SHALL render them as buttons in the header row of
`CaseDetail`. The transitions SHALL come from dossiq's own transition endpoint,
not from OpenRegister's `available-actions`, until the case graph is projected
onto the engine lifecycle.

**Feature tier**: V1

#### Scenario: Display available transitions on case detail
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a case in status "In behandeling"
- **AND** the workflow defines transitions "Goedkeuren" (requires role Afdelingshoofd) and "Terugsturen" (any role)
- **AND** the user has role "Behandelaar"
- **WHEN** the user opens the case page
- **THEN** only the "Terugsturen" button SHALL be displayed in the header row

#### Scenario: No transitions available
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a case in a final status "Afgehandeld"
- **WHEN** the user opens the case page
- **THEN** no transition buttons SHALL be displayed
- **AND** the case status area SHALL say the case is closed

## ADDED Requirements

### Requirement: A transition executes from the case page (REQ-STE-11)

You move the case to its next status from the case page. Pressing a transition
button SHALL open a confirmation with an optional comment, and confirming SHALL
post the transition to `StatusTransitionService`. The page SHALL then show the
new status without a reload, and the guard failures the service reports SHALL
be shown in the dialog instead of moving the case.

#### Scenario: A handler advances a case
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a seeded case whose current status allows one transition to "In behandeling"
- **WHEN** the handler presses that transition and confirms
- **THEN** the case's status SHALL be the target status
- **AND** the transition history SHALL hold one new row with the handler as actor
- **AND** the header row SHALL list the transitions of the new status

#### Scenario: A failed guard keeps the case where it is
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a transition whose guard requires a document the case lacks
- **WHEN** the handler confirms that transition
- **THEN** the dialog SHALL show the guard's message
- **AND** the case's status SHALL be unchanged

### Requirement: Closing a case asks for the result (REQ-STE-12)

When you close a case, you pick the result. A transition whose target status is
final SHALL require a result type from the case type's result types before it
executes, and SHALL write the result on the case in the same request.

#### Scenario: A final transition records a result
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a case one transition away from a final status
- **AND** the case type has result types "Verleend" and "Geweigerd"
- **WHEN** the handler takes that transition and picks "Verleend"
- **THEN** the case SHALL be in the final status
- **AND** the case's `result` SHALL reference a result of type "Verleend"

#### Scenario: No result, no close
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** the same case
- **WHEN** the handler tries to confirm the final transition without a result
- **THEN** the confirm button SHALL be disabled
- **AND** the case SHALL stay in its current status

### Requirement: Suspend, resume, extend and reopen from the Actions menu (REQ-STE-13)

You suspend, resume, extend or reopen a case from its Actions menu. The Actions
menu on `CaseDetail` SHALL offer Suspend when the case type allows suspension,
Resume when the case is suspended, Extend term when the case type allows
extension, and Reopen when the case is in a final status. Each SHALL ask for a
reason and SHALL run through the existing deadline and transition services, so
the deadline moves and the audit row is written.

#### Scenario: Suspend then resume
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** an open case of a case type with `suspensionAllowed` true
- **WHEN** the handler chooses Suspend, gives a reason and confirms
- **THEN** the case SHALL show a suspended marker and the Resume action
- **AND** choosing Resume SHALL clear the marker and recompute the deadline

#### Scenario: Extend the term
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** an open case of a case type with `extensionAllowed` true and `extensionPeriod` P14D
- **WHEN** the handler chooses Extend term and confirms
- **THEN** the case's deadline SHALL be fourteen days later than before
- **AND** `extensionCount` SHALL be one higher

#### Scenario: Reopen a closed case
@e2e tests/e2e/case-lifecycle-on-the-page.spec.ts

- **GIVEN** a case in a final status
- **WHEN** a handler with the reopen scope chooses Reopen, gives a reason and confirms
- **THEN** the case SHALL be in the case type's initial status
- **AND** `isFinalStatus` SHALL be false

#### Scenario: A user without the reopen scope is refused
@e2e exclude authorization is asserted by a PHPUnit test on CaseLifecycleController; Playwright runs as admin and cannot take a lesser role

- **GIVEN** a case in a final status
- **WHEN** a user without the reopen scope posts the reopen request
- **THEN** the request SHALL be refused with 403
- **AND** the case SHALL stay closed
