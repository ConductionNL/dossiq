# example-set-contact-moments-name-their-contact Specification

## Purpose
What dossiq does for example set contact moments name their contact. The requirements were first written by the change `example-set-contact-moments-name-their-contact` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-example-set-contact-moments-name-their-contact/`) and cover: A contact moment names its contact.

## Requirements

### Requirement: A contact moment names its contact

Every contact moment in the Zuiddrecht example set SHALL name, as its `contact`, the requester of the case it is on.

#### Scenario: Henk's calls
- **GIVEN** the set is installed
- **WHEN** a handler opens Henk Bakker's parking permit case's communication
- **THEN** each of the four moments SHALL name Henk Bakker as its contact
