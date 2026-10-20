# case-types Delta

**Status**: proposed
**OpenSpec changes**:

- [status-badge-text-token](../../)

## ADDED Requirements

### Requirement: A status badge sets the theme's pill text

A status badge SHALL set its text to the theme's pill text for its hue (`--nl-color-<hue>-text`) when the theme names
one, and to the hue mixed 65% into the main text (the main text for a `-light` colour) otherwise.

#### Scenario: under the Zuiddrecht set

- **GIVEN** a case in a status coloured blue
- **THEN** its status pill text is the set's #234A78 on #EAF0F7
