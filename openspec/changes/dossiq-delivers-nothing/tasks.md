# Tasks — dossiq delivers nothing: extraction of outbound delivery to integriq

## Phase 1: Besluitvorming publication via the ADR-041 seam (this PR)

- [x] Dispatch `OCA\Integriq\Event\DeliveryRequestedEvent` from `PublicationService::publish()`
      (FQN-string `class_exists()` guard; fail-closed refusal recorded when integriq is absent,
      the dispatch throws, the event is unhandled, or zero subscriptions match).
- [x] Record the delivery outcome (`requested` / `unrouted` / `refused` + correlationId, eventId,
      requestedAt) in the publication entry's `delivery` block; never roll back the publication.
- [x] Add `lib/Listener/DeliveryConcludedListener.php`: filter `getSourceApp() === 'dossiq'`,
      terminal statuses only, correlation-id matched, idempotent projection onto the case.
- [x] Register the listener in `ListenerRegistrar` by FQN string, guarded on integriq's event class.
- [x] Declare `publications` + `publishedAt` on the `case` schema in `dossiq_register.json` AND
      `dossiq_mock_register.json` (closes the silent-drop defect: OpenRegister stripped both fields
      on every save since the service shipped).
- [x] Test stubs `tests/Stubs/Integriq/Event/{DeliveryRequestedEvent,DeliveryConcludedEvent}.php`
      mirroring integriq's real constructor signatures verbatim; bootstrap wiring.
- [x] Unit tests: refusal on not-handled, refusal on dispatch-throw, unrouted on zero matches,
      requested with correlationId persisted, terminal projection (delivered + abandoned),
      provenance filter, idempotency, no-match warning path.

## Phase 2: StUF-ZKN outbound re-point — staged

Blocked on: **endpoint + vault credential migration design** (dossiq stores `stufEndpoint` register
objects with `vault://` references resolved via `IAppConfig` keys `stuf.vault.<sha256>`; integriq
sources resolve credentials through the OpenRegister broker (`authentication.credentialRef`) — the
migration needs a repair step mapping one onto the other, and secrets cannot be copied blind).
NOT blocked on wave-3: integriq's StufZkn bridge is seam-based (`StufZknProviderInterface`), not the
legacy runner.

### Ruling 2026-09-12: re-point through integriq, do not retire

These four verbs were listed for retirement as dead capability. Measured against integriq before
acting, and the premise does not hold in the direction that matters. Under `openconnector/lib/`,
`creeerZaak`, `actualiseerZaak`, `geefZaakDetails` and `vrijBericht` appear in **zero** files, and
`genereerZaakIdentificatie` only in a spec scenario. The outbound ZKN translator emits a bare
`zakLk01` kennisgeving and writes no `<stuf:functie>` element at all, so it cannot distinguish
`creeerZaak` from `actualiseerZaak`, which this app's own spec requires as an explicit SHALL. Its
`Lv01` code is StUF-BG person and address lookups, unrelated to `geefZaakDetails`. Retiring here and
citing integriq would have moved four requirements onto an app that implements roughly one of them.

The ruling is that **the specs stay in dossiq, and dossiq configures integriq as the leaf providing
the capability instead of carrying its own transport.** dossiq keeps stating what it needs of a
StUF-ZKN zaaksysteem; it stops owning the SOAP client, the circuit breaker, the vault and the retry
job. The gap that exposes is integriq's to close, and it is named below rather than left for
whoever deletes the code to discover.

- [ ] Re-point `creeerZaak` and `actualiseerZaak` through the ADR-041 delivery seam, the same
      `DeliveryRequestedEvent` path phase 1 already uses for publication. These are the two integriq
      can carry today, as a `zakLk01` kennisgeving.
- [ ] **integriq-side prerequisite:** teach the outbound ZKN translator to write `<stuf:functie>`,
      so a create and an update are distinguishable on the wire. Until this lands, re-pointing the
      two verbs above silently collapses them into one message.
- [ ] **integriq-side prerequisite:** a synchronous Lv01 `geefZaakDetails` round-trip and a Du01
      `genereerZaakIdentificatie` round-trip. Both are request and response rather than
      fire-and-forget, so they do not fit `DeliveryRequestedEvent` as it stands and need their own
      seam.
- [ ] Re-point `geefZaakDetails` and `genereerZaakIdentificatie` once that seam exists. Do NOT
      delete dossiq's implementations before then: they are the only implementations the fleet has.
- [ ] Re-point `StufController::outbound()` (`vrijBericht`) at integriq's `stuf_message` intake via
      the delivery seam or `StufZknSyncService`, keeping the admin surface read-only views.
- [ ] Repair step: migrate `stufEndpoint` objects to integriq `source` objects
      (`type=stuf-zkn`) with broker credential refs; keep `zaaksysteemMapping` (case↔extern id) in
      dossiq — it is case data.
- [ ] Delete the outbound half of `lib/Service/Stuf/` (`StufOutboundTransport`, `StufHttpClient`,
      `CircuitBreakerService`, `StufVaultService`, `NeedsInputDispatcher`, `StufRetryJob`) once
      nothing references it; the inbound responder half stays until its own extraction is ruled on.
- [ ] Keep `openspec/specs/stuf-zkn-outbound/spec.md` in dossiq, and record in it which party now
      performs each requirement. The orchestration, circuit breaker, retry and credential clauses
      describe behaviour dossiq still depends on, so they change owner rather than cease to exist,
      and integriq's `stuf-zkn-bridge` spec should reference them rather than restate them.

## Phase 3: Notificaties + webhooks re-point — staged

Blocked on: **integriq subscription provisioning** (the ZGW notificaties fan-out is per-abonnement
callback URLs stored as register objects; re-pointing needs either integriq's `notificaties` action
kind fed per-callback, or a delivery-request per callback — the mapping is a design decision for
the integriq side, tracked in `absorb-dossiq-deliveries` phase 2).

- [ ] Re-point `ZgwService::publishNotification()` through the delivery seam
      (`deliveryKind: 'zgw-notificatie'`); retire `NotificatieService`'s raw Guzzle client and one
      of the two duplicated SSRF CIDR lists.
- [ ] Re-point `Actions/CallWebhookHandler` (slug-resolved webhooks) through the seam
      (`deliveryKind: 'webhook'`); retire `Transitions/WebhookHandler` (inline-URL, no SSRF guard)
      outright — flows configure the URL on the integriq subscription instead.
- [ ] E2E: `tests/e2e/spec-coverage/integrations-and-flows.spec.ts` asserts the webhook action
      vocabulary — update alongside the retirement.

## Phase 4: Dormant/mocked transports — staged

Blocked on: **no production transport exists to move** (Berichtenbox has only `MockAdapter`;
`LogZgwExternalAdapter` returns synthetic `PUSH_DEFERRED`; DSO-LV production needs OAuth2 + OIN
PKIoverheid mTLS that was never built). These are integriq build-out items, not extractions.

Re-verified 2026-09-09 against `development`. The Berichtenbox half is not waiting on
anyone's code. A MijnOverheid transport needs a Logius aansluiting — credentials issued to
the municipality — and a PKIoverheid certificate on the OIN behind it. Both are procurement
with a lead time, so this phase stays open on a purchase order rather than on a branch, and
should not be read as unfinished engineering. `BerichtenboxReadStatusJob` is still absent
from `appinfo/info.xml`, so its cron is still dead.

- [ ] When a real MijnOverheid transport is commissioned: build it as an integriq provider quintet
      (controller + provider seam + sync service + `*_message` schema + retry job, the
      IwmoIjw/StufZkn pattern); dossiq keeps `BerichtenboxRoutingService` (channel choice is
      domain) and calls through the delivery seam.
- [ ] Also fix en route: `BerichtenboxReadStatusJob` is not registered in `appinfo/info.xml`.
      Re-verified 2026-09-10: still unregistered, and DELIBERATELY so rather than by oversight.
      The class docblock at `lib/BackgroundJob/BerichtenboxReadStatusJob.php:9-28` now says why,
      in the words the audit needed: integriq ships only `BerichtenboxClientMock`, so scheduling
      it today would poll a mock daily and write back a read status the mock invents, and a cron
      that appears to confirm citizens are reading post no instance has sent is worse than no
      cron. The open item is therefore "register it in the same change that binds a real
      transport", not "fix a dead cron".
- [ ] When cross-municipality ZGW push activates: bind `ZgwExternalAdapterInterface` to an
      integriq source (`zgw-external`) instead of a local HTTP client.
- [ ] DSO-LV: land production auth (OAuth2 + PKIoverheid mTLS) on integriq's `dso-omgevingsloket`
      source config; `DsoLvAuthService` retires.

## Phase 5: Shillinq billing export — staged

Blocked on: **shillinq must define its event contract first** (per ADR-041 the target app defines
the typed event; shillinq is an NC sibling, so integriq HTTP is the wrong seam — this is a
cross-app command to shillinq, mirroring the decidiq `DecisionRequestedEvent` precedent).

- [ ] shillinq defines `InvoiceIngestRequestedEvent` (its repo, its openspec).
- [ ] `ShillinqIntegrationService::exportInvoice()` dispatches it (class-guarded, fail-closed);
      the blocking `sleep()` retry loop and the bearer-token HTTP client retire.
