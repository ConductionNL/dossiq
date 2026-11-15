# menu-board-icons Specification

## Purpose
The navigation draws the icons of the board: every entry in dossiq's app menu carries the icon its drawn board gives it.

## Requirements

### Requirement: The menu entries carry the board's icons

The menu SHALL draw My work with InboxOutline, the team queue with FormatListBulleted, All cases with
BriefcaseOutline, Tasks with CheckboxMarkedOutline, Contacts with AccountMultipleOutline and Organisations with Domain,
and every icon the menu names SHALL be registered in `src/icons.js`.

#### Scenario: DqZijbalk

- **GIVEN** the simple menu
- **THEN** each entry shows the icon the DqZijbalk board draws beside it
