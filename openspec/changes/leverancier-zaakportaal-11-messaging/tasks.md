# Tasks — Member 11: Secure Per-Case Messaging (code)

Traces to giant tasks 3.6 and 2.5; spec REQ-006.

- [ ] Implement `SupplierMessageService.sendMessage(caseRef, supplierRef, body, attachmentRefs)` — inbound message
- [ ] Implement `SupplierMessageService.addResponse(messageRef, handlerResponse)` — outbound message
- [ ] Implement `SupplierMessageService.getConversationHistory(caseRef, supplierRef)` — scoped chronological thread
- [ ] Implement `RouteSupplierMessageJob` — dispatch handler inbox + email notification on creation
- [ ] Create `MessageController`: GET /messages?caseId=, POST /messages, GET /messages/{id}
- [ ] Apply member 04 scope validation; enforce write-once immutability (no update path)
- [ ] Implement `MessageComposer`: required text, ≤5 attachments ≤10 MB each, server-side type/size validation
- [ ] Build `MessageThread`: chronological, inbound (light bg) vs outbound (white bg)
- [ ] Create `MessageBubble`: sender, timestamp, body, downloadable attachments
- [ ] Show "Bericht verstuurd" success and clear the composer
- [ ] Test message sending with attachments
- [ ] Test handler-response workflow (Procest → portal)
- [ ] Test email notifications to handler and supplier
- [ ] Test 403 on messaging an out-of-scope case
