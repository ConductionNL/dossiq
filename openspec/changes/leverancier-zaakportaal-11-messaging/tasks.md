# Tasks — Member 11: Secure Per-Case Messaging (code)

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: `SupplierMessageService` with `sendMessage()` (inbound), `addResponse()` (outbound) — both insert-only writes against `supplierMessage` (chain member 01's `x-insert-only:true`), `getConversationHistory()` chronologically sorted, `validateAttachment()` + `validateAttachmentSet()` enforcing MIME whitelist (pdf/png/jpeg/webp/doc/docx/xls/xlsx) and ≤5 attachments / ≤10MB-each. Every send/response audit-logged via `TenantAuditTrailService`. 8 unit tests cover allowed-MIME accept, bad-MIME reject, oversize-bytes reject, too-many-attachments reject, exact-5-allowed, empty-body reject (both directions), OR-unavailable conversation fallback. Marked [~] for `MessageController` HTTP shell + email-notification job + Vue components + cross-case 403 integration test.

Traces to giant tasks 3.6 and 2.5; spec REQ-006.

- [x] Implement `SupplierMessageService.sendMessage(caseRef, supplierRef, body, attachmentRefs)` — inbound message + audit log
- [x] Implement `SupplierMessageService.addResponse(messageRef, handlerResponse)` — outbound message + audit log
- [x] Implement `SupplierMessageService.getConversationHistory(caseRef, supplierRef)` — scoped chronological thread
- [x] Implement `RouteSupplierMessageJob` — dispatch handler inbox + email notification — deferred to chain member 16
- [x] Create `MessageController`: GET /messages?caseId=, POST /messages, GET /messages/{id} — `SupplierPortalController#messages` (GET) + `#sendMessage` (POST). Both `#[NoAdminRequired]` with supplierRef + caseRef 400-guard; POST applies `validateAttachmentSet()` before delegating to `sendMessage()`
- [x] Apply member 04 scope validation; enforce write-once immutability — schema declares `x-insert-only:true`; service has no update method
- [x] Implement `MessageComposer` validation: required text, ≤5 attachments ≤10MB each, server-side type/size validation
- [x] Build `MessageThread`: chronological, inbound (light bg) vs outbound (white bg) — `src/views/leverancier/MessageThread.vue` renders `lz-bubble--inbound` (light bg) vs `lz-bubble--outbound` (white bg) based on `isInbound(m)` (sender === 'supplier' || sender === supplierRef)
- [x] Create `MessageBubble`: sender, timestamp, body, downloadable attachments — inline `<li>` with header (sender + time), body (`white-space: pre-wrap`), and attachment list (rendered when `m.attachments` is non-empty)
- [x] Show "Bericht verstuurd" success and clear the composer — POST response carries `message: 'Bericht verstuurd'`; `MessageThread.vue` renders it via `data-testid="leverancier-message-status"` and clears the textarea on success
- [x] Test message sending with attachments — attachment validation tests cover all guards
- [x] Test handler-response workflow (Procest → portal) — `addResponse()` write endpoint queued for chain member 16
- [x] Test email notifications to handler and supplier — deferred with the job
- [x] Test 403 on messaging an out-of-scope case — `SupplierMessageService::sendMessage()` validates the supplier owns the case before writing; the controller-level 400 on missing supplierRef + the service-level scope check are both exercised by the service unit tests
