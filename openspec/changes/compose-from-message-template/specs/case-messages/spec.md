# case-messages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [compose-from-message-template](../../)

## Purpose

A handler writes a message from the case type's templates instead of from nothing.

## ADDED Requirements

### Requirement: Choosing a message template fills the message

A compose surface SHALL offer the active templates of the case's case type that fit its channel (a template without a
channel fits every channel). Choosing one SHALL fill the subject and the body with that template rendered for the case,
and both SHALL stay editable. Without fitting templates the surface SHALL show no template choice.

#### Scenario: DqBerichtVersturen

- **GIVEN** a Woo case whose case type has a status update template for the Berichtenbox
- **WHEN** the handler opens Bericht and chooses that template
- **THEN** Subject and Message read the template filled for this case, and the handler can still change both
