# werkvoorraad-intelligent-queue Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [my-work-team-chips-in-toolbar](../../)

## ADDED Requirements

### Requirement: Assigned to me draws the team's name chips on the toolbar row

For a coordinator, the Assigned to me view SHALL draw the team workload's title and hint under the page header and the
name chips (the reader's own first, each with its open-case count) at the start of the toolbar row, before Filter and the
view switch. The covering filter SHALL show only as a chip on the Active line, not as a tab. The workload SHALL be read
once for both parts. A reader the workload endpoint refuses SHALL see neither part.

#### Scenario: DqAanMijToegewezen

- GIVEN Pieter, a coordinator with 7 open cases, opens Assigned to me
- THEN the toolbar row reads "Pieter Jansen (u) 7, Anouk Bakker 9, ..., Filter, view switch"
- AND the Active line reads "Actief: [Inclusief werk waarvoor u waarneemt ×] Alles wissen"
