# Delta: openregister-integration

## Changes from base spec

### Schema Registration (ENHANCED)
- Refactored store.js from 13 individual conditional registrations to data-driven pattern
- Now registers all 27 schemas defined in the spec (configuration + instance + ZGW support)
- Added: statusRecord, catalogus, zaaktypeInformatieobjecttype, caseProperty, caseDocument,
  caseObject, customerContact, decisionDocument, dispatch, document, documentLink, usageRights,
  kanaal, abonnement

### Frontend Availability Check (NEW)
- Created `src/utils/openregisterCheck.js` with `checkOpenRegisterStatus()` and `getStatusMessage()`
- Checks both OpenRegister app availability and Procest register configuration
- Returns localized status messages for admin guidance
