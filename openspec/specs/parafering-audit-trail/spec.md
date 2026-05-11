---
status: retired
retired_in: procest-adopt-or-abstractions
canonical_home: case-management/spec.md
---

> **RETIRED — consume OR `audit-trail-immutable`.**
>
> Delegation tracking (`actorType`, `onBehalfOf`) is recorded as audit
> context on lifecycle transitions in the consolidated case-management
> annotation. Immutability is provided by OR's `audit-trail-immutable`
> capability, not a procest-specific custom service. See ADR-022.
>
> This file is preserved as a historical appendix. Refer to
> `case-management/spec.md` for canonical audit semantics.

## ADDED Requirements

### Requirement: Immutable Parafering Audit Trail

The system SHALL maintain an immutable audit trail of all parafering actions on a voorstel. Parafeeractie records SHALL NOT be updated or deleted after creation.

**Feature tier**: V1

#### Scenario: Complete audit trail for a voorstel

- **WHEN** a voorstel has passed through 5 parafering steps
- **AND** an auditor reviews the voorstel detail
- **THEN** the audit trail SHALL show for each step: step number, step type (advies/parafering/accordering), actor display name, action (parafered/returned/advised/skipped), timestamp, comments or advice text
- **AND** the entries SHALL be displayed in chronological order

#### Scenario: No delete or update operations exposed

- **WHEN** the frontend renders the audit trail
- **THEN** no edit or delete buttons SHALL be available on parafeeractie records
- **AND** the frontend SHALL NOT call update or delete API endpoints for parafeeracties

### Requirement: Route Modification Audit

The system SHALL record route modifications (skipped steps, added ad-hoc steps) in the audit trail with the modifier identity and reason.

**Feature tier**: V1

#### Scenario: Skipped step recorded in audit

- **WHEN** a manager skips a step with reason "Niet van toepassing voor dit type vergunning"
- **THEN** a parafeeractie with action "skipped" SHALL be created
- **AND** the comment SHALL contain the reason text
- **AND** the actor SHALL be the manager who performed the skip

#### Scenario: Original route preserved

- **WHEN** a parafeerroute is modified on a specific voorstel
- **THEN** the original route definition (at time of submission) SHALL be preserved in the voorstel object
- **AND** the current modified route SHALL be distinguishable from the original

### Requirement: Delegation Audit

The system SHALL clearly distinguish delegated parafering actions in the audit trail, showing both the delegate and the principal.

**Feature tier**: V1

#### Scenario: Delegation displayed in audit trail

- **WHEN** a parafeeractie has actorType "delegate" with onBehalfOf set
- **THEN** the audit trail SHALL display: "Geparafeerd door [delegate display name] namens [principal display name]"
- **AND** both delegate and principal SHALL be searchable in audit queries

### Requirement: Audit Trail Export

The system SHALL support exporting the parafering audit trail for archival purposes.

**Feature tier**: V1

#### Scenario: Export audit trail as list

- **WHEN** the user clicks "Exporteren" on the voorstel detail audit trail section
- **THEN** the system SHALL generate a structured export of all parafeeracties for the voorstel
- **AND** the export SHALL include: step details, actor names, actions, timestamps, comments, delegation info
