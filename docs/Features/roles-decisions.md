# Roles and Decisions

The roles and decisions feature manages the assignment of roles to case participants and the recording of formal decisions (besluiten) within cases.

## Roles

Roles define who is involved in a case and in what capacity. Common role types in Dutch government case management include:

- **Behandelaar** (Handler) -- The case worker responsible for processing the case.
- **Initiator** -- The person or organization that initiated the case (e.g., the applicant).
- **Belanghebbende** (Stakeholder) -- Parties with a vested interest in the case outcome.
- **Adviseur** (Advisor) -- Internal or external advisors consulted during case processing.
- **Medeinitiator** -- Co-initiators of a case.

### Role Management
- Assign roles to users or external parties.
- Track role history (who was assigned when).
- Role-based access control for case data.
- ZGW-compatible role types via the roltype mapping.

## Decisions (Besluiten)

Decisions are formal administrative outcomes recorded against a case:

- **Decision types** -- Configurable via the besluittype ZGW mapping.
- **Decision recording** -- Capture the decision text, date, and responsible authority.
- **Decision publication** -- Track whether a decision requires publication.
- **Appeal tracking** -- Link decisions to subsequent objection (bezwaar) cases.

## Status

This feature is defined in the spec at `openspec/specs/roles-decisions/spec.md` and is partially implemented through the ZGW API mapping configuration.
