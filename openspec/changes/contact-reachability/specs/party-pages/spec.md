# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [contact-reachability](../../)

## ADDED Requirements

### Requirement: A contact says how the person can be reached

A contact page SHALL show, for a reader who may read the person, the person's portal mailbox when portaliq says the
person has an active portal account, the newest contact moment, and that phone and e-mail are not in the base
registration.

#### Scenario: DqContact

- **GIVEN** Sanne de Vries has an active DigiD portal account and wrote through the portal on 4 October
- **THEN** Bereikbaar via reads "Mijn Zuiddrecht, berichtenbox" and "Laatst contact op 4 oktober, via webformulier"
