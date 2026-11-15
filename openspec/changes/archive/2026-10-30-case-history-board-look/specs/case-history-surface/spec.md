# case-history-surface Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-history-board-look](../../)

## ADDED Requirements

### Requirement: The History tab draws the board's header and chips

The case History tab SHALL draw its title at 17px and 700 with a 1.2 line height, its subtitle at 14px 4px below it,
16px above the kind chips. A kind chip SHALL be 36px high, 18px rounded, 14px at 600; the chosen chip SHALL be filled
with the main text colour and no outline, its count a bubble in a lighter ink with light text.

#### Scenario: DqZaakHistorie

- **GIVEN** a case on its History tab
- **THEN** "Historie van de zaak" is 17px and "Alles" is a filled chip 18px rounded at 600 with no outline
