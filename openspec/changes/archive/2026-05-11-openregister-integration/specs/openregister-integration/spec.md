# Delta: openregister-integration

## ADDED Requirements
### Requirement: Schema Registration — Enhanced
The system SHALL satisfy the behaviour described as "Schema Registration — Enhanced".

- Refactored store.js from 13 individual conditional registrations to data-driven pattern
- Now registers all 27 schemas defined in the spec (configuration + instance + ZGW support)
- Added: statusRecord, catalogus, zaaktypeInformatieobjecttype, caseProperty, caseDocument,
  caseObject, customerContact, decisionDocument, dispatch, document, documentLink, usageRights,
  kanaal, abonnement

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour

### Requirement: Frontend Availability Check — New
The system SHALL satisfy the behaviour described as "Frontend Availability Check — New".

- Created `src/utils/openregisterCheck.js` with `checkOpenRegisterStatus()` and `getStatusMessage()`
- Checks both OpenRegister app availability and Procest register configuration
- Returns localized status messages for admin guidance

#### Scenario: behaviour satisfied

- **GIVEN** the system is configured per this requirement
- **WHEN** the described trigger occurs
- **THEN** the system SHALL exhibit the documented behaviour
