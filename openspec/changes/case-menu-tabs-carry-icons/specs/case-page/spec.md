## ADDED Requirements

### Requirement: The case tabs in the Acties menu carry icons

Every case tab that sits in the Acties menu (an `overflow` tab) SHALL declare
an icon that dossiq registers, so the menu draws it in front of the label.

#### Scenario: Notities in the menu
- GIVEN the case page in the simple profile
- WHEN the Acties menu lists the Notes tab
- THEN the tab declares a registered icon (NoteTextOutline)
