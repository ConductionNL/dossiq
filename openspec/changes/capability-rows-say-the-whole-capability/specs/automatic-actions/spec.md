# automatic-actions

## ADDED Requirements

### Requirement: A transition can generate a document from a template and open a related case (REQ-CRS-03)

A transition or step SHALL be able to carry an action that generates a document from a named template and files it on the case, and an action that creates a related case of a named case type linked to this one. Both SHALL run when the transition runs, without a handler starting them.

Rows: `3.9` (dossiq matrix).

#### Scenario: A transition files a letter and opens a follow-up case
- **GIVEN** a transition configured to generate the acknowledgement letter and to open a related enforcement case
- **WHEN** a handler moves a case through that transition
- **THEN** the letter is in the case documents and a new enforcement case links back to the case
