---
kind: code
---

# Proposal: store-publish-passthrough

## Why

OpenRegister's canonical routes now include `POST /api/store/publish` (openregister #4996). dossiq takes those routes but answers the store with its own StoreController, which had no `publish()`, so a call to that route would fail at dispatch with a 500.

## What changes

`StoreController::publish()` passes `item` to OpenRegister's `GenericStoreService::publish()` and returns its answer. It answers 401 to an anonymous caller and 400 when there is no item. dossiq's descriptor declares no publish fields or groups, so the engine answers `not_publishable`: nothing can be published from dossiq yet, but the route answers instead of failing.
