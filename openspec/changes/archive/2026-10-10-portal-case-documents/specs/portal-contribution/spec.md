## ADDED Requirements

### Requirement: The case collection publishes the documents a resident may see (REQ-PORTAL-018)
`mijnZaken` MUST declare `documents: {label: "Stukken", provider: "caseDocuments"}`.
`caseDocuments(caseId)` MUST return, for that case only, each document whose
status is final or archived, whose confidentiality is `openbaar`, `beperkt_openbaar` or
`zaakvertrouwelijk`, and that a decision on the case links or that the
organisation sent, as `{id, title, kind, date, file}` with the file on the
case object. A document a decision links MUST carry `kind: decision` and the
decision date.

#### Scenario: The resident sees the decision and the letters sent to them
- **GIVEN** a case with a final decision letter, a final outgoing letter, a draft, an internal note and a neighbour's incoming letter
- **WHEN** the resident opens the case in the portal
- **THEN** the decision letter MUST be listed first under Decision and the outgoing letter under Documents, and nothing else from the organisation

#### Scenario: Nothing is answered without a case
- **GIVEN** OpenRegister is not available, or the case id is empty
- **WHEN** portaliq asks for the case's documents
- **THEN** the answer MUST be an empty list
