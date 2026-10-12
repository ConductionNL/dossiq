## ADDED Requirements

### Requirement: The handler asks for missing information from the case page (REQ-AVR-05)

The case page MUST offer a handler the act of asking the applicant for missing information while the case has a running term and no open aanvullingsverzoek. The form MUST collect the missing items (at least one), the recipient, the term the applicant gets within the case type's declared suspension limit, the pause reason and the rationale, and MUST send them to `POST /api/cases/{caseId}/information-request`. Before the handler sends, the form MUST say that the summary and the list of missing items go to the applicant and that the term is suspended once the letter has gone out. A refusal MUST be shown as the server's own sentence; the form MUST NOT report success on its own.

#### Scenario: The handler sees what the applicant receives

- **GIVEN** a case with a running term and no open aanvullingsverzoek
- **WHEN** the handler opens "Aanvulling vragen" on the case page
- **THEN** the form says that the summary and the missing items go to the applicant, and that the term is suspended once the letter has gone out

#### Scenario: A request that is already open is refused with the server's sentence

- **GIVEN** a case that already waits on the applicant
- **WHEN** the handler sends the form
- **THEN** the form shows the refusal sentence the server returned, and the case keeps its one open request
