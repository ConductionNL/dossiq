# Tasks — Member 11: Secure Per-Case Messaging (code)

Traces to giant tasks 3.6 and 2.5; spec REQ-006.

- [~] Implement `SupplierMessageService.sendMessage(caseRef, supplierRef, body, attachmentRefs)` — inbound message — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `SupplierMessageService.addResponse(messageRef, handlerResponse)` — outbound message — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `SupplierMessageService.getConversationHistory(caseRef, supplierRef)` — scoped chronological thread — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `RouteSupplierMessageJob` — dispatch handler inbox + email notification on creation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `MessageController`: GET /messages?caseId=, POST /messages, GET /messages/{id} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Apply member 04 scope validation; enforce write-once immutability (no update path) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `MessageComposer`: required text, ≤5 attachments ≤10 MB each, server-side type/size validation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build `MessageThread`: chronological, inbound (light bg) vs outbound (white bg) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `MessageBubble`: sender, timestamp, body, downloadable attachments — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Show "Bericht verstuurd" success and clear the composer — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test message sending with attachments — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test handler-response workflow (Procest → portal) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test email notifications to handler and supplier — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test 403 on messaging an out-of-scope case — deferred to downstream cycle / fleet-wide adoption (handoff)
