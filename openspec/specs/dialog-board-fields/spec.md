# dialog-board-fields Specification

## Purpose
What dossiq does for dialog board fields. The requirements were first written by the change `dialog-board-fields` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-dialog-board-fields/`) and cover: A dialog's reason field has the board's height.

## Requirements

### Requirement: A dialog's reason field has the board's height

The suspend act SHALL ask its reason in a one-line field beside the days. Other acts SHALL keep a text area. The Woo
decision dialog SHALL draw its reasons field four lines tall.

#### Scenario: DqVolgendeStap "Opschorten"

- **GIVEN** the lifecycle dialog with Suspend chosen
- **THEN** the reason is a one-line field beside the days

#### Scenario: DqBesluit

- **GIVEN** the Woo decision dialog
- **THEN** the reasons field is four lines tall
