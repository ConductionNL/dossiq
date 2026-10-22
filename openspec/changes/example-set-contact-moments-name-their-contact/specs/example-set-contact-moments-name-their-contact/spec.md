# example-set-contact-moments-name-their-contact

## ADDED Requirements

### Requirement: A contact moment names its contact

Every contact moment in the Zuiddrecht example set SHALL name, as its `contact`, the requester of the case it is on.

#### Scenario: Henk's calls
- **GIVEN** the set is installed
- **WHEN** a handler opens Henk Bakker's parking permit case's communication
- **THEN** each of the four moments SHALL name Henk Bakker as its contact
