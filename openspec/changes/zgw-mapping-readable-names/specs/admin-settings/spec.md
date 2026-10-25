# admin-settings Delta: zgw-mapping-readable-names

## ADDED Requirements

### Requirement: A ZGW mapping row names its resource

Each row of the ZGW API mapping table SHALL show its resource's name in the reader's language, for every resource the default mappings store; a stored key without a name SHALL show as the key.

#### Scenario: Case type
- GIVEN the mapping stored under `caseType`
- THEN its row reads "Zaaktype"
