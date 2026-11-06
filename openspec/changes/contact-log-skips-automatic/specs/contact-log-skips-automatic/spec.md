## ADDED Requirements

### Requirement: A contact moment says when the system sent it by itself

The contactmoment schema SHALL have a boolean `automatic`, true on a message the system sent without a person, and absent on a contact a person recorded.

#### Scenario: the receipt
- GIVEN the automatic receipt on a case
- THEN its contact moment carries `automatic: true`

### Requirement: The contact log lists only the contacts a person recorded

The case's contact log and the Contact tab's count SHALL leave out contact moments with `automatic` set, while the conversation shows every moment.

#### Scenario: DqZaakContact
- GIVEN a case with four recorded contacts and an automatic receipt
- THEN the log reads "4 vastgelegd", the tab counts 4, and the conversation shows five messages
