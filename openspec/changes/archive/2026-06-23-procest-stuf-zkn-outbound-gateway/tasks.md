# Tasks: procest-stuf-zkn-outbound-gateway

## 1. Outbound services (ported, rebound to procest)

- [x] 1.1 Port the 6 exception classes (`StufException` + Circuit/Timeout/PayloadTooLarge/VrijBerichtNotRegistered/ZaaktypeNotMapped) into `lib/Service/Stuf/`
- [x] 1.2 Port `StufVaultService` (rebind APP_ID; bound app-config key to ≤64 chars)
- [x] 1.3 Port `StufRegisterAccess` (rebind APP_ID; register id from app config)
- [x] 1.4 Port `StufMessageParser` (point NS constants at `StufMessageBuilder`)
- [x] 1.5 Port `NeedsInputDispatcher` (NC INotificationManager admin alerts; deliberately NOT NotificatieService, which is the ZGW NRC publisher)
- [x] 1.6 Port `CircuitBreakerService` (rebind APP_ID; hash circuit keys to ≤64 chars)
- [x] 1.7 Port `StufMessageHandler` (de-pipelinq audit fields → `bronEntiteit`/`bronId`/`gerelateerdeZaakId`)
- [x] 1.8 Port `ContactBetrokkeneMapper` (re-key mapping filters to `bronEntiteit`/`bronId`)
- [x] 1.9 Port `StufHttpClient` (HTTPS-only + mTLS + verify-always; Procest-StUF UA)
- [x] 1.10 Port `StufAdapterService` orchestrator (`request`→`case`; rebind retry-job FQN; re-key mappings)
- [x] 1.11 Port `StufRetryJob` background job (rebind namespace + adapter)

## 2. Builder reconciliation

- [x] 2.1 Fold the outbound StUF-0310 methods (Lk01/Lk02/Lv01/Du01 + WSSE wrap + ULID + payload limits + NS_SOAPENV/NS_WSSE/PAYLOAD_LIMIT_BYTES) into `StufMessageBuilder`
- [x] 2.2 Add the `StufVaultService` constructor dependency for WSSE; keep the inbound builders (`buildSoapEnvelope`/`buildBv01`/`buildFo01`/`buildSoapFault`/`buildStuurgegevens`) unchanged

## 3. Schemas (de-pipelinq'd) re-homed into procest's register

- [x] 3.1 Add `lib/Settings/register.d/80-stuf-zkn-outbound.json` under register `procest`
- [x] 3.2 `stufMessage`: drop `gerelateerdeRequestId`/`gerelateerdeContactId`; add `gerelateerdeZaakId` + `bronEntiteit`/`bronId`
- [x] 3.3 `zaaksysteemMapping`: re-key `pipelinqEntiteit`/`pipelinqId` → `bronEntiteit` (case/contact)/`bronId` + `caseId`

## 4. REST + routes

- [x] 4.1 Merge `outbound`/`endpoints`/`messages` + async `inkomend` into `StufController` (keep inbound handlers)
- [x] 4.2 Add `stuf#endpoints|messages|outbound|inkomend` routes to `appinfo/routes.php`

## 5. Frontend

- [x] 5.1 Add `src/services/stufApi.js`
- [x] 5.2 Port `StufEndpoints.vue` + `StufAuditLog.vue` as inner-content tabs (no NcSettingsSection wrapper)
- [x] 5.3 Surface both as `CnSettingsSection`s in `AdminRoot.vue`

## 6. Wiring

- [x] 6.1 Bump `appinfo/info.xml` version (forces register re-import)
- [x] 6.2 Confirm DI auto-wiring resolves all ported services (verified live: controller 400/401, not 500)

## 7. Tests

- [x] 7.1 Port + rebind `StufMessageBuilderOutboundTest`, `CircuitBreakerServiceTest`, `StufMessageParserTest`, `ContactBetrokkeneMapperTest`

## 8. Verify

- [x] 8.1 `php -l` all new/changed PHP — pass
- [x] 8.2 `phpcs` (lib scope) — exit 0
- [x] 8.3 `npm run build` — webpack compiled, StUF in bundle
- [x] 8.4 Register re-import on :8080 — `stufEndpoint`/`stufMessage`/`zaaksysteemMapping` schemas landed
- [x] 8.5 Routes register on :8080 — endpoints/messages 401, inkomend 400, zaken regression-safe
- [x] 8.6 Outbound degrades gracefully against a dormant endpoint — HTTP 200 `{success:false}`, audit row persisted, `StufRetryJob` queued

## 9. Deferred (NOT in this change)

- [ ] 9.1 Wire inbound stub handlers to real OR `case` create/update — separate change
- [ ] 9.2 pipelinq → procest auto-sync event-seam — separate feature
- [ ] 9.3 pipelinq removal of the engine (STEP 2)
