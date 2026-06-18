---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# vth-leges-engine Specification

## Purpose
Calculates leges fees for a case from the active seeded rule set, returning the base fee, applied modifiers, and total. Supports offsetting prior fees (verrekening), refunds (teruggaaf), and additional billing (navordering), and exposes authenticated leges endpoints that log every transaction in an audit trail.
## Requirements
### Requirement: Leges fee calculation

The system SHALL calculate leges fees for a case from the active seeded rule set, returning the base fee, applied modifiers, and total fee.

**Spec ref**: REQ-VTH-004-A

#### Scenario: Calculate fee with size modifier

- **WHEN** a fee is calculated for an Omgevingsvergunning case with activiteit Verbouwing and size 250 m²
- **THEN** the service SHALL return a total fee equal to the base fee plus the size modifier defined in the active rule set

### Requirement: Verrekening, teruggaaf, and navordering

The system SHALL support offsetting prior fees (verrekening), refunds (teruggaaf), and additional billing (navordering), each recorded in an audit trail.

**Spec ref**: REQ-VTH-004-B, REQ-VTH-004-C, REQ-VTH-004-D

#### Scenario: Verrekening offsets prior fees

- **WHEN** verrekening is applied with a prior fee
- **THEN** the final fee SHALL equal the calculated fee minus the offset

#### Scenario: Teruggaaf before beschikking

- **WHEN** a case is withdrawn before the beschikking stage and a refund is requested
- **THEN** the service SHALL record a full refund and write an audit entry

#### Scenario: Navordering records additional fee

- **WHEN** navordering is invoked with an amount and reason
- **THEN** an additional fee SHALL be recorded and a notification sent

### Requirement: Leges API and audit trail

The system SHALL expose authenticated leges endpoints and log every leges transaction (calculation, verrekening, refund, navordering).

**Spec ref**: REQ-VTH-004

#### Scenario: Leges endpoints available

- **WHEN** an authenticated user posts to the leges calculate endpoint for an accessible case
- **THEN** the controller SHALL return the calculation and append an audit entry

