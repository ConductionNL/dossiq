## ADDED Requirements

### Requirement: Parafeeractie Schema Registration

The system SHALL register a `parafeeractie` schema in the Procest OpenRegister configuration with properties: voorstel (reference), step (integer), actor (string, user UID), actorType (enum: user, delegate), onBehalfOf (string, optional user UID), action (enum: parafered, returned, advised, skipped), comment (string, optional), advice (string, optional for advisory steps), timestamp (datetime), mandate (string, optional mandate reference).

**Feature tier**: V1
**Schema.org type**: `schema:Action`
**ZGW mapping**: No direct equivalent; contributes to besluit audit trail
**CMMN concept**: HumanTask completion event

#### Scenario: Schema is available after app install

- **WHEN** the Procest app is installed or updated
- **THEN** the `parafeeractie` schema SHALL be registered in the Procest register via the repair step
- **AND** the schema SHALL enforce required properties: voorstel, step, actor, action, timestamp

### Requirement: Paraferen Action (Approve)

The system SHALL allow the active actor at the current step to paraferen (endorse) the voorstel, advancing it to the next step.

**Feature tier**: V1

#### Scenario: Successful parafering

- **WHEN** the parafeerder "Jan de Vries" clicks "Paraferen" on a voorstel at step "Teamleider"
- **THEN** the system SHALL create a parafeeractie with action "parafered", actor "jan.devries", timestamp now
- **AND** the voorstel SHALL advance to the next step
- **AND** the next actor SHALL receive a Nextcloud notification
- **AND** Jan SHALL NOT be able to paraferen again on this voorstel at this step

#### Scenario: Only active actor can paraferen

- **WHEN** a user who is NOT the active actor at the current step attempts to paraferen
- **THEN** the system SHALL reject the action
- **AND** the "Paraferen" button SHALL NOT be visible to non-active users

### Requirement: Terugsturen Action (Return with Comments)

The system SHALL allow the active actor to return the voorstel to the steller with a mandatory comment explaining the reason.

**Feature tier**: V1

#### Scenario: Return voorstel with comment

- **WHEN** the afdelingshoofd clicks "Terugsturen" with comment "Financiele paragraaf ontbreekt"
- **THEN** the system SHALL create a parafeeractie with action "returned" and the comment
- **AND** the voorstel status SHALL change to "teruggestuurd"
- **AND** the steller SHALL receive a notification: "Voorstel teruggestuurd door [actor]: [comment]"

#### Scenario: Comment is mandatory for return

- **WHEN** the actor clicks "Terugsturen" without entering a comment
- **THEN** the system SHALL prevent the submission
- **AND** the comment field SHALL show a validation error: "Reden is verplicht bij terugsturen"

#### Scenario: Resubmit after return

- **WHEN** the steller edits the document on a returned voorstel and clicks "Opnieuw indienen"
- **THEN** the voorstel status SHALL change back to "in_parafering"
- **AND** the currentStep SHALL be set to the step that returned it (resume from that step)
- **AND** the returning actor SHALL be notified of the resubmission

### Requirement: Adviseren Action (Non-binding Opinion)

The system SHALL allow actors at advisory steps to submit non-binding advice. Advisory steps advance automatically after advice is submitted.

**Feature tier**: V1

#### Scenario: Submit advice

- **WHEN** the adviseur submits advice: "Akkoord, mits bouwkosten worden gecontroleerd"
- **THEN** the system SHALL create a parafeeractie with action "advised" and the advice text
- **AND** the voorstel SHALL advance to the next step
- **AND** the advice SHALL be visible to the steller and subsequent parafeerders on the voorstel detail

#### Scenario: Advisory step button label

- **WHEN** the current step type is "advies"
- **THEN** the action button SHALL display "Adviseren" instead of "Paraferen"

### Requirement: Paraferen Namens (On Behalf Of)

The system SHALL support delegation where a user with a configured mandate can paraferen on behalf of another user.

**Feature tier**: V1

#### Scenario: Delegate parafering

- **WHEN** secretaresse Bakker has a mandate to paraferen on behalf of wethouder Van Dam
- **AND** Bakker opens the voorstel task assigned to Van Dam
- **THEN** Bakker SHALL see an option "Paraferen namens Van Dam"
- **AND** the parafeeractie SHALL record: actorType "delegate", actor "bakker", onBehalfOf "vandam", mandate reference

#### Scenario: Delegation in audit trail

- **WHEN** a delegate parafering is recorded
- **THEN** the audit trail SHALL clearly display: "Geparafeerd door [delegate] namens [principal]"

### Requirement: Besluit Registration from Voorstel

The system SHALL support registering a formal besluit (decision) when the college has decided on a voorstel. This uses the existing `decision` schema and `BrcController`.

**Feature tier**: V1

#### Scenario: Manual besluit registration

- **WHEN** the secretariaat clicks "Besluit registreren" on a voorstel with status "geaccordeerd" or "aangeboden"
- **AND** enters: besluit tekst, ingangsdatum, besluittype
- **THEN** a decision object SHALL be created via the existing decision schema
- **AND** the voorstel status SHALL change to "besloten"
- **AND** the case activity timeline SHALL show: "Besluit vastgesteld: [tekst]"

#### Scenario: No RIS connector configured

- **WHEN** no RIS connector is configured in the system
- **THEN** the "Aanbieden aan RIS" button SHALL NOT be displayed
- **AND** a "Markeer als besloten" button SHALL allow manual besluit registration
