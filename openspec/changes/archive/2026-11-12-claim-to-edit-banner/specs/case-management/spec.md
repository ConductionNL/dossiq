# case-management Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [claim-to-edit-banner](../../)

## ADDED Requirements

### Requirement: A reader who is not the handler can claim the case to edit it

The case page SHALL read the record rights, so its edit controls are off while `@self.can.update` is false. While
`@self.can.update` is false and `@self.can["claim-as-handler"]` is true, the banner row SHALL show "Voeg jezelf toe als
behandelaar om te kunnen bewerken" with one button that runs the declared action `claim-as-handler` on the case and
reads the page again.

#### Scenario: A group member opens a case they do not handle

- **GIVEN** a case whose `@self.can` is `{ update: false, "claim-as-handler": true }` for the reader
- **WHEN** the reader opens the case
- **THEN** the edit controls are off and the banner offers "Voeg mij toe als behandelaar"
- **AND** pressing it runs `claim-as-handler` on the case, after which the page follows the new rights

#### Scenario: The handler opens the case

- **GIVEN** a case whose `@self.can.update` is true for the reader
- **THEN** the banner does not show and every edit control is there
