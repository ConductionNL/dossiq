# Design — Member 11: Secure Per-Case Messaging (code)

## Scope

Full messaging slice — service, routing job, controller, and Vue composer/thread — reading the
write-once `SupplierMessage` schema from member 01.

## Declarative-first (ADR-031) note

No new schema. `SupplierMessage` records via OpenRegister ObjectService (ADR-001). Immutability is
enforced at the API layer here: outbound and inbound messages are created (never updated), honoring
the write-once flag member 01 declared.

## Approach

- `sendMessage(caseRef, supplierRef, body, attachmentRefs)` creates an inbound `SupplierMessage`.
- `addResponse(messageRef, handlerResponse)` creates an outbound message.
- `getConversationHistory(caseRef, supplierRef)` returns the scoped thread chronologically.
- `RouteSupplierMessageJob` dispatches a notification to the handler inbox + email on creation.
- `MessageComposer` (text required, ≤5 files ≤10 MB each), `MessageThread`, `MessageBubble`.

## Security (ADR-005)

- Scope-validated: a supplier may only message/read its own cases.
- Messages immutable post-creation (audit/compliance) — no update path exposed.
- Attachment type/size validated server-side.
- Composer lives in its own component; modal isolation respected where dialogs are used.
