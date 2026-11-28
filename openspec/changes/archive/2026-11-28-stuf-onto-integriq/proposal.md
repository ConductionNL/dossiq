# Proposal: stuf-onto-integriq

## Why

Decision 193 and ADR-118: StUF is a standards adapter and belongs in integriq. Integriq gained a
StUF-ZDS case client (integriq#2657, change `stuf-zds-case-client`): it sends creeerZaak,
actualiseerZaak, geefZaakdetails, genereerZaakIdentificatie and vrij berichten, retries, keeps
the audit row and receives the asynchronous Bv01/Fo01. Dossiq's own copy of that machinery is
now dead weight.

## What changes

- `stufEndpoint` 1.1.0 gains `integriqSource`, the integriq StUF-ZKN source the endpoint sends
  through. `zaaktypeMappings` stays as dossiq's case-type-to-zaaktype configuration.
- The admin vrijBericht (`POST /api/stuf/outbound`) is asked of integriq with
  `StufZdsCaseRequestedEvent`.
- `/api/stuf/inbound` and `/api/stuf/inkomend` forward the raw confirmation to integriq with
  `StufZdsConfirmationReceivedEvent` for one release; integriq authenticates and records it.
- `StufZdsCaseConcludedListener` turns a failed conclusion into the Notifier's stuf_* notification.
- Deleted: StufAdapterService, StufOutboundTransport, StufHttpClient, CircuitBreakerService,
  StufMessageHandler, StufCaseMappingStore, StufMessageParser, StufServices, StufMessageBuilder,
  StufRetryJob and their exceptions and tests.

## Not in this change (STOP)

`/api/stuf/cases`, `/api/stuf/persons` and their legacy aliases `/api/stuf/zaken`,
`/api/stuf/personen` make dossiq a StUF provider (zakLk01, zakLv01, npsLv01, edcLk01 answered from
dossiq's own cases). Integriq has no receiver for them: `StUFZKNService` and `StUFBGService` exist
but no route reaches them, and they write integriq's own `zaken` register, not dossiq's cases. So
StufSoapRequestDispatcher, StufZknMessageResponder, StufResponseBuilder, StufEnvelopeInspector,
StufVaultService, StufRegisterAccess and StufFieldMappingService stay until integriq has one.
