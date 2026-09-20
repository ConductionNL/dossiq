---
kind: config
depends_on: [adopt-connection-registry, digital-post-reaches-integriq]
---

# Proposal: digital-post-consumes-integriq

Competitor gap scan of 2026-09-18, pack b, row 6.6 "Digital post to
citizens (Digital Post, Berichtenbox, Postex)". Scored partial. Owner
dossiq. Size M.

## Where this sits

Two changes, one row each, built in this order. `digital-post-reaches-integriq`
(row 12.9) is the seam: the adapter that dispatches integriq's send event and
refuses when the seam is unavailable, `kind: code`. This change (row 6.6) is
the citizen-facing use on top of it, `kind: config`. Neither replaces the
other, and `depends_on` above records which comes first. The compose dialog
stays unregistered until the seam lands, because a button that hands a letter
to `MockAdapter` is worse than no button.

## Why

dossiq can report a letter delivered that was never sent, and there is no
button to press that would send one.

`lib/Service/BerichtenboxAdapter/` ships exactly one implementation,
`MockAdapter`, whose own class comment says it simulates sending without
external calls. `SubstitutableAdapterRegistrar` resolves the interface
through a `berichtenbox_adapter` config key and falls back to the mock with
an honest sentence: nothing reaches Mijn Overheid. That sentence is the
whole of the transport today, because no real adapter class exists for an
administrator to name.

`src/dialogs/BerichtenboxComposeDialog.vue` is darker still. It is
referenced nowhere: not by `src/registry.js`, not by `src/manifest.json`,
not by another component. The only occurrence of its name in the repository
is its own `name:` line.

integriq closed its side today. `berichtenbox-digital-post-adapter`
(integriq#2062) ships `DigitalPostProviderInterface` with send, status,
inbound polling and `activationRefusals`, bindings for log, berichtenbox
and postex, a `digitalPostMessage` schema that tracks the lifecycle, and
`DigitalPostSendRequestedEvent` carrying either a tracked message id or a
structured refusal and never both. Its DI factory serves the mock only
while the Logius feature flag is unset; once an operator turns that flag
on, the instance resolves to a binding that refuses and names what is
missing, rather than to a mock that says delivered.

dossiq reaches none of it. The four integriq events dossiq knows are
DeliveryConcluded, ConnectionStatusReported, ConnectionRefreshRequested and
DeliveryRequested.

## What changes

- A handler sends a letter to a citizen's digital post from the case, and
  the compose dialog is reachable from the case page for the first time.
- `BerichtenboxService` dispatches integriq's send event instead of calling
  a local adapter, so the transport, the certificate and the provider
  choice all live where integriq put them.
- A refusal reaches the handler as a sentence. A message integriq could not
  send is a message the case shows as not sent, with the reason, never as
  sent.
- The mock adapter is removed rather than left as a fallback. An instance
  with no provider configured refuses and names what is missing, which is
  the rule integriq's own factory already follows.
- Delivery and read status arrive through the status event and land on the
  case timeline beside the other messages.

## Ownership

integriq owns the providers, the credentials, the certificate reference and
the lifecycle of a sent message. dossiq owns the case, the recipient, what
the letter says and what the handler sees. Neither owns the other half:
dossiq shipping a Berichtenbox client would be a second one, and integriq
deciding which case a letter belongs to would be a second matcher.

The live network leg is still blocked on integriq's side, and this change
does not unblock it. integriq#2062 names all three blockers: Logius BBK
OAuth client credentials, a PKIoverheid Services-server certificate, and
`CredentialBrokerService::issueSigningMaterial` in OpenRegister. Until they
land, a flagged instance refuses with those words, and that refusal is
exactly what this change puts in front of a handler.

## ADRs

- Company ADR-022: dossiq consumes the transport and wraps none.
- Company ADR-102: absent configuration fails closed with a status.

## Capabilities

- Modified: `berichtenbox-integration`: the transport is integriq's, the
  compose surface is reachable, and a simulated delivery is never reported
  as a real one.

## Impact

`lib/Service/BerichtenboxService.php`;
`lib/Service/BerichtenboxAdapter/` (removed);
`lib/AppInfo/Registrar/SubstitutableAdapterRegistrar.php`;
`lib/AppInfo/Registrar/CrossAppListenerRegistrar.php`;
`lib/Listener/DigitalPostDeliveredListener.php` (new);
`src/dialogs/BerichtenboxComposeDialog.vue`; `src/registry.js`;
`src/manifest.json` (the header action that opens it).
