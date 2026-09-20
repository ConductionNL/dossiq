---
kind: code
depends_on: []
---

# Proposal: digital-post-reaches-integriq

Gap scan of the parity ledger, 2026-09-18, pack d, row 12.9 "Digital post or
berichtenbox". Rated `partial` for dossiq.

## Why

Every letter dossiq composes is delivered to nothing. The compose dialog
works, the routing service works, the message is stored on the case, and
`BerichtenboxService::getAdapter()` hands it to `MockAdapter`, because
`lib/Service/BerichtenboxAdapter/` contains exactly two files: the interface
and the mock.

The seam was built for this and says so. `SubstitutableAdapterRegistrar`
reads the app config key `berichtenbox_adapter` and its header states that
the real contract belongs in integriq or filinq and that dossiq must carry
neither. It was waiting for integriq.

integriq shipped on 2026-09-18. PR 2062, "one seam for digital post, and a
flag that refuses rather than simulates", added `BerichtenboxClient`,
`BerichtenboxClientUnavailable`, `DigitalPostSendRequestedEvent`,
`DigitalPostDeliveredEvent`, `DigitalPostSendRequestedListener`,
`DigitalPostProvidersController` and the inbound and status jobs. The half
dossiq was waiting for exists.

## What changes

- A second adapter in `lib/Service/BerichtenboxAdapter/`, selectable by the
  existing config key, that dispatches integriq's send event and returns the
  reference integriq answers with.
- It reaches integriq the way dossiq reaches every sibling, through
  `FleetAppId`, never by writing another app's id into a literal. integriq's
  id is moving and a hardcoded name would make this a silent no-op rather
  than an error.
- Delivery and read status arrive as events, not as polling. dossiq listens
  for integriq's delivered event and updates the message on the case.
- The adapter refuses when integriq is absent or its digital post flag is
  off. It never falls back to the mock, because a mock that stands in for a
  real letter is how a citizen stops being notified without anyone noticing.
- The mock stays, selectable by config, for development. What changes is that
  it is never the silent default on an instance that asked for real post.

## Where this sits

`dossiq-delivers-nothing` audited every outbound surface on 2026-09-02 and
staged Berichtenbox as phase 4, with an honest blocker: "No real transport
exists... Nothing to move today, there is no transport." That blocker lifted
on 2026-09-18. This change is phase 4, and it is small because that audit
already decided where the line runs.

The umbrella `competitor-parity-2026-09` carries the neighbouring row 12.14
the same way, pointing at `dossiq-delivers-nothing` for the webhooks and the
NRC fan-out. Those stay staged; this is only the Berichtenbox leg.

## Ownership

integriq owns the Berichtenbox conversation, the credentials and the
provider. dossiq owns the case side: which message, about which case, to
which citizen, and what the case records afterwards.

## ADRs

- Company ADR-017: external systems belong to integriq.
- Company ADR-022: consume the sibling, carry no protocol.

## Capabilities

- Modified: `berichtenbox-integration`: the adapter reaches integriq, and
  refuses rather than simulating.

## Impact

`lib/Service/BerichtenboxAdapter/` gains one adapter,
`lib/AppInfo/Registrar/SubstitutableAdapterRegistrar.php` gains its branch,
one listener under `lib/Listener/`, unit tests. No frontend, no new endpoint.
