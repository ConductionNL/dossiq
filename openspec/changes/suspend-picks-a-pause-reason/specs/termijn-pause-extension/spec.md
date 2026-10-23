# termijn-pause-extension Delta: suspend-picks-a-pause-reason

**Status**: draft
**Scope**: the Opschorten gesture on the case page (lifecycle menu) and its endpoint.

## ADDED Requirements

### Requirement: Suspending from the case page MUST offer the case type's pause reasons

When the case type declares pause reasons, the Opschorten form SHALL offer them in a picker with an Other entry, SHALL show the picked reason's legal basis under the days, and SHALL post the picked reason's key. The server SHALL store a declared key on the pause and SHALL refuse a key the case type does not declare. Without declared reasons, the form SHALL keep the free-text reason.

#### Scenario: A handler picks a declared reason

- **GIVEN** a case whose case type declares the pause reason "Wacht op machtiging van de verzoeker" with legal basis "Awb 4:5, hersteltermijn"
- **WHEN** the handler opens Opschorten and picks that reason
- **THEN** the days field shows "Awb 4:5, hersteltermijn" under it
- **AND** Opschorten can be confirmed without free text
- **AND** the pause is registered with that reason's key, and its name as the rationale

#### Scenario: A handler writes their own reason

- **GIVEN** the same case type
- **WHEN** the handler picks Other
- **THEN** the free-text reason shows and must be filled before Opschorten can be confirmed
- **AND** the pause is registered with no reason key, as before

#### Scenario: A case type without pause reasons

- **GIVEN** a case whose case type declares no pause reasons
- **WHEN** the handler opens Opschorten
- **THEN** the form shows the free-text reason and the days, as before

#### Scenario: An undeclared key is refused

- **GIVEN** a case whose case type does not declare the key "made-up"
- **WHEN** a client posts Opschorten with `pauseReason: "made-up"`
- **THEN** the server answers 422 with code `pause_reason_not_declared`
- **AND** no pause is registered

### Requirement: A pause reason can carry its default days

A pause reason MAY declare `defaultDays`, a positive whole number. The Opschorten form SHALL fill "Days the applicant
is given" with it when the handler picks that reason; a reason without it, and Other, SHALL leave the days as they
were. Zero, a negative or a non-number SHALL read as no default.

#### Scenario: A reason with 28 days

- **GIVEN** the case type's reason "Wacht op machtiging" declares 28 default days
- **WHEN** the handler picks it in Opschorten
- **THEN** the days read 28, and the handler can change them before confirming
