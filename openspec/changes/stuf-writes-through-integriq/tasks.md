# Tasks: stuf-writes-through-integriq

- [x] `ConnectedSystemCaseWriter` and `ConnectedSystemDocumentFiler`, with tests on real collaborators.
- [x] `StufProviderQueryListener` hands a mapped write to the writer and returns its outcome.
- [x] `stufEndpoint` 1.2.0: `acceptsWrites`, `documentTypeMappings`; case `originSystem`, `originIdentifier`.
- [x] Integriq event stubs refreshed from integriq's `stuf-provider-writes` change.
- [ ] Live pass: creeerZaak, updateZaak and voegZaakdocumentToe through integriq's route on an
  instance with both apps, the background service account set and an endpoint that accepts writes.
