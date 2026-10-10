## ADDED Requirements

### Requirement: A resident's writes on their own case are declared (REQ-PORTAL-013)
The contribution MUST declare exactly one `type: update` action on `case`
carrying `citizenWrite` with `typeField: caseType`, `typeRegister: dossiq` and
`typeSchema: caseType`, scoped by `portalSubject`, whose `fields` whitelist
holds only fields the applicant supplied, and MUST serve it to every audience
the case collection is served to.

#### Scenario: A resident opens their case screen
- **GIVEN** a resident signed in with DigiD with a dossiq case of type "Melding openbare ruimte"
- **WHEN** they open the case in the portal
- **THEN** the case screen MUST show the case instead of "This case cannot be changed from the portal."

#### Scenario: A resident corrects their description
- **GIVEN** the same case in status Ontvangen, where the case type opens `description` to `client`
- **WHEN** the resident corrects the description and saves
- **THEN** the case MUST carry the new description, and the handler MUST see the amendment on the case timeline

### Requirement: The case type decides what a resident may change and withdraw (REQ-PORTAL-014)
A case type MUST be able to declare `portalWritable`, `portalAmendmentWindow`,
`portalDocumentWindow` and `portalWithdrawal` in the shapes portaliq reads. A
case type MUST NOT be saved with a `portalWithdrawal` whose `targetStatus` is
not reachable from each of its open statuses.

#### Scenario: A resident withdraws a report still waiting to be picked up
- **GIVEN** a case type declaring withdrawal while Ontvangen, onto Ingetrokken, and a resident's case in Ontvangen
- **WHEN** the resident withdraws it in the portal and confirms
- **THEN** the case MUST be in status Ingetrokken and the assignee MUST be told

#### Scenario: An unreachable withdrawal status is refused
- **GIVEN** a functional administrator editing a case type whose workflow has no transition from Ontvangen to Ingetrokken
- **WHEN** they save a withdrawal onto Ingetrokken while Ontvangen
- **THEN** the save MUST be refused with a sentence naming Ingetrokken
