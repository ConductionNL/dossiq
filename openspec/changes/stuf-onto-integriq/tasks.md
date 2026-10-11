# Tasks: stuf-onto-integriq

- [x] `stufEndpoint.integriqSource` (schema 1.1.0, digest recorded).
- [x] `StufIntegriqBridge`: vrijBericht through `StufZdsCaseRequestedEvent`, confirmations through
      `StufZdsConfirmationReceivedEvent`; unanswered is unavailable, never sent.
- [x] `StufController::outbound()` and `inbound()` on the bridge; endpoint health reads
      "linked to an integriq source".
- [x] `StufZdsCaseConcludedListener` registered by name in `CrossAppListenerRegistrar`.
- [x] Outbound classes, `StufRetryJob`, `StufMessageBuilder` and their tests deleted; namespace
      constants to `StufNamespaces`.
- [ ] STOP: provider routes `/api/stuf/cases|persons|zaken|personen` wait for an integriq receiver.
- [ ] Next release: remove the `/api/stuf/inbound` and `/api/stuf/inkomend` forwarders.
