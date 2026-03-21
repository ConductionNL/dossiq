# appointment-scheduling Specification

## Problem
Integrate appointment scheduling (afsprakenbeheer) into Procest case flows for cases that require physical service delivery at a municipal counter (balie). Citizens can book appointments as part of case submission or at any point during case handling. The system integrates with existing municipal appointment backends (Qmatic, JCC Afspraken) via a plugin architecture, and supports self-service cancellation and modification.

## Proposed Solution
Implement appointment-scheduling Specification following the detailed specification. Key requirements include:
- Requirement 1: Appointments bookable as part of case flow
- Requirement 2: Pluggable appointment backend architecture
- Requirement 3: Citizen self-service appointment management
- Requirement 4: Appointment lifecycle and reminder notifications
- Requirement 5: Appointment visibility in case context

## Scope
This change covers all requirements defined in the appointment-scheduling specification.

## Success Criteria
#### Scenario 1.1: Book appointment during case intake
#### Scenario 1.2: Book appointment from case detail view
#### Scenario 1.3: Multiple appointments per case
#### Scenario 1.4: Appointment as required task
#### Scenario 1.5: Appointment links to case participants
