# kvk-client-retired

## Why

Standards adapters live in integriq, not in dossiq (ADR-118, hydra design section 5). Dossiq shipped
a KvK Handelsregister client (`KvkHandelsregisterAdapterInterface`, `KvkApiAdapter`,
`LogKvkHandelsregisterAdapter`, `KvkLookupResult`) and a registrar that bound it. No class in
`lib/` injected the interface: the Integrations page already said so on the KvK card. Integriq
holds the KvK source and its `kvk` property source, and a requester's company data is kept current
through integriq's registry subscription (#3595).

## What changes

- The four KvK client classes, `KvkRegistrar` and the KvK contract fixture are deleted.
- The KvK card stays Not available. Its message now says dossiq ships no KvK client and integriq
  holds the source.
- The `kvkCompany` schema and its seed rows stay (capability `kvk-register`).

## Impact

No caller changes, because there was none. The app config keys `integration.kvk.mode`,
`integration.kvk.baseUrl` and `integration.kvk.apiKey` are no longer read. No schema change, no
repair step, no version bump. No integriq PR is needed: its KvK source and provider exist.
