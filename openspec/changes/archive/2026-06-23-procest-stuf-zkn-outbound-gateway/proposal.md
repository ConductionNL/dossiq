# Proposal: procest-stuf-zkn-outbound-gateway

kind: code — ports the production-grade **StUF-ZKN/BG OUTBOUND engine** from pipelinq into procest, where zaakgericht-werken belongs. Procest already owns StUF-ZKN INBOUND (StufController reception + StufMessageBuilder envelopes + StufFieldMappingService + the ZGW rules layer + a native `case` schema), but had **no outbound StUF** (no HTTP client / mTLS / vault / circuit-breaker / retry / audit log). This change re-homes that engine.

This is **STEP 1 of a 2-step migration**: build/receive in procest now. pipelinq's removal of the same engine is a separate later step (STEP 2) and is explicitly out of scope here.

## Summary

A legacy zaaksysteem (Centric Key2Zaken, PinkRoccade, …) speaks StUF-ZKN/BG 0310 over SOAP. Procest needs to push cases to it (`creeerZaak` / `actualiseerZaak`), query it synchronously (`geefZaakDetails`), pre-allocate zaak identificaties (`genereerZaakIdentificatie`), and send free messages (`vrijBericht`) — all over mutually-authenticated HTTPS with a WSSE UsernameToken header, with per-endpoint circuit-breaking, exponential-backoff retries, and an append-only audit log.

**What changes (procest side):**

1. **Outbound services** ported into `lib/Service/Stuf/`: `StufHttpClient` (HTTPS-only + mTLS + verify-always transport), `StufVaultService` (credential references), `StufMessageParser` (Bv01/La01/Fo02), 6 exception classes, `CircuitBreakerService`, `StufMessageHandler` (audit-log persist), `StufAdapterService` (orchestrator), `StufRegisterAccess`, `NeedsInputDispatcher`, `ContactBetrokkeneMapper`, and a `StufRetryJob` background job — all rebound from pipelinq's `OCA\Pipelinq` namespace/DI/appconfig to procest's.
2. **Builder reconciliation** — the outbound StUF-0310 envelope methods (Lk01/Lk02/Lv01/Du01 + WSSE wrapping + ULID referentienummer + payload limits) are **folded into procest's existing `StufMessageBuilder`** rather than shipping a second builder. The existing inbound builders (`buildSoapEnvelope`/`buildBv01`/`buildFo01`/`buildSoapFault`/`buildStuurgegevens`) are preserved byte-for-byte; the builder gains a `StufVaultService` dependency for WSSE.
3. **Schemas** re-homed into procest's register (`procest`) via `lib/Settings/register.d/80-stuf-zkn-outbound.json`: `stufEndpoint`, `stufMessage`, `zaaksysteemMapping`. The pipelinq-specific references are de-pipelinq'd — `stufMessage.gerelateerdeRequestId`/`gerelateerdeContactId` become a generic `gerelateerdeZaakId` plus a `bronEntiteit`/`bronId` source-ref pair; `zaaksysteemMapping.pipelinqEntiteit`/`pipelinqId` become `bronEntiteit` (case/contact) + `bronId` + `caseId`.
4. **REST** — `StufController` gains outbound `outbound`/`endpoints`/`messages` (JSON, admin-gated) plus an async `inkomend` confirmation receiver (WSSE-verified PublicPage webhook) that matches a Bv01 crossRefnummer back to its outbound row. Routes added to `appinfo/routes.php`.
5. **Frontend** — `StufEndpoints.vue` + `StufAuditLog.vue` (+ `stufApi.js`) surfaced as admin settings sections in `AdminRoot.vue`.

## De-pipelinq'd appconfig key bounds

Nextcloud's `IAppConfig` enforces a 64-character key limit. pipelinq's raw `stuf.vault.<sha256>` key (75 chars) and `stuf.cb.<count|open>.<endpointId>` keys overflow this. The ported `StufVaultService` and `CircuitBreakerService` therefore truncate/hash their keys to stay ≤64 chars (`stuf.v.<40hex>`, `stuf.cb.{c,o}.<32hex>`).

## Out of scope (DEFERRED)

- Wiring procest's INBOUND stub handlers (`handleZakLk01` etc.) to real OpenRegister `case` create/update — net-new behaviour, separate change.
- The pipelinq → procest auto-sync event-seam (emit an outbound StUF call when a `case` is created/updated) — separate feature.
- pipelinq's `lib/Service/Zgw/*` REST bridge — explicitly NOT ported.
- pipelinq's removal of the engine (STEP 2).
