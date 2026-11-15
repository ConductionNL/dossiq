# dossiq-store-surface: delta for store-publish-passthrough

## ADDED Requirements

### Requirement: The publish route answers

`POST /api/store/publish` MUST answer: 401 to an anonymous caller, 403 to a user `canPublish()` does not admit, 400 without an `item`, and otherwise the outcome OpenRegister's store service gives for dossiq's store.

#### Scenario: a publish to dossiq's store

@e2e exclude Asserted in tests/Unit/Controller/StoreControllerTest.php; dossiq declares no publish fields, so the outcome is not_publishable.

- GIVEN a signed-in user
- WHEN they post an item to /api/store/publish
- THEN the answer is 200 with outcome not_publishable, never a server error
