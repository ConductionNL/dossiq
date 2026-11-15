# besluitvorming-workflow (delta)

## MODIFIED Requirements

### Requirement: REQ-BVW-007 Mandaatbesluit MUST validate signing authority against the mandaatregister

Before a Mandaatbesluit case can advance to "Besluit genomen", the system SHALL ask decidiq's delegatie- en mandaatregister whether the signing official holds the authority for the case's `mandaatCategorie`, through the `delegatedAuthorityGuard`.

#### Scenario REQ-BVW-007-A: Valid mandate allows transition to Besluit genomen

- **GIVEN** a Mandaatbesluit case with `mandaatCategorie` "VTH-M-04" whose signing official holds an effective toedeling in decidiq for that act
- **WHEN** the workflow guard asks decidiq
- **THEN** the transition guard MUST pass

#### Scenario REQ-BVW-007-B: Insufficient mandate blocks transition with clear error

- **GIVEN** a Mandaatbesluit case whose signing official holds no toedeling for the act, or one whose ceiling the amount exceeds
- **WHEN** the workflow guard asks decidiq
- **THEN** the transition to "Besluit genomen" MUST be blocked
- **AND** the case handler MUST see: "De ondertekenende ambtenaar heeft onvoldoende mandaat voor dit besluit. Raadpleeg het mandaatregister." (or the ceiling sentence)

#### Scenario REQ-BVW-007-C: An unreachable register refuses

- **GIVEN** decidiq is not installed or its register cannot be read
- **WHEN** the workflow guard asks
- **THEN** the guard MUST NOT pass, and the handler sees why
- **AND** there is no manual confirmation path: the remedy is a toedeling in decidiq (open question for Ruben, refactor Q8)
