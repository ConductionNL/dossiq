# case-types Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-type-handler-label](../../)

## ADDED Requirements

### Requirement: A case type names its handler

A case type MAY declare `handlerLabel`. The case's report card SHALL label its handler row with it, and with
"Behandelaar" when the case type declares none.

#### Scenario: DqSociaalDomein

- **GIVEN** the Melding Jeugdwet case type with `handlerLabel: "Regisseur"`
- **THEN** De melding reads "Regisseur: Fatma Kaya"
