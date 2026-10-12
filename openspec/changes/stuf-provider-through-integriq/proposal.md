# Proposal: stuf-provider-through-integriq

## Why

Decision 199 (11 Oct 2026): dossiq's StUF provider side moves into integriq. Dossiq parsed SOAP,
checked WS-Security and built StUF answers itself, and its four message handlers were stubs: a
zakLv01 was always answered with an empty zakLa01 and a zakLk01 with a Bv01 for a change dossiq never
made. Integriq now owns the wire (integriq change `stuf-provider-receiver`); dossiq answers the
question from its cases.

## What changes

- `StufProviderQueryListener` answers integriq's typed events for the integriq source a
  `stufEndpoint` names in `integriqSource`: a case query by identificatie, with the zaaktype from the
  endpoint's `zaaktypeMappings`; a person query from the case that names the BSN as its initiator.
  Case and document changes are refused, so the case system gets a Fo01 instead of a false Bv01.
- `/api/stuf/cases`, `/persons`, `/zaken` and `/personen` forward the raw envelope to integriq for
  one release.
- Deleted: `StufSoapRequestDispatcher`, `StufZknMessageResponder`, `StufResponseBuilder`,
  `StufEnvelopeInspector`, `StufVaultService`, `StufFieldMappingService`, `StufNamespaces` and their
  tests.

## Not changed

`StufRegisterAccess` stays. It is not provider code: it reads the `stufEndpoint` and `stufMessage`
rows for the admin REST routes, the connection test, the outbound bridge and the new listener.
