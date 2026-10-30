# case-types Delta

## ADDED Requirements

### Requirement: The Fields tab draws the board's cards

The case type page's Fields tab SHALL hold the fields editor and the field rights only. The case type form, the raw
terms and the case list columns SHALL stay on the page on a Properties tab listed under the tab strip's Actions menu.

#### Scenario: Opening the Fields tab

- **GIVEN** an administrator on a case type page
- **WHEN** they open the Fields tab
- **THEN** they see the Fields card and the "who sees and fills what" card, with the choice list and the parent type beside them

#### Scenario: Editing the raw terms

- **GIVEN** the Terms card on Phases and tasks
- **WHEN** the administrator presses Edit
- **THEN** the Properties tab opens with the terms form on it
