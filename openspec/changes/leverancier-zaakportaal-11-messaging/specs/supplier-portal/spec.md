# supplier-portal Specification — Member 11: Secure Per-Case Messaging

---
status: proposed
---

## Purpose

Let suppliers message case handlers and view immutable conversation threads. Consumes the
write-once `SupplierMessage` schema from member 01 and scoping from member 04.

## ADDED Requirements

### Requirement: Supplier Message Sending and Routing

The system SHALL create an immutable inbound message and notify the case handler.

#### Scenario: Sending a message notifies the handler

- GIVEN a supplier viewing one of its own cases
- WHEN it submits a message with optional attachments (≤5 files, ≤10 MB each)
- THEN a `SupplierMessage` with `direction` = inbound SHALL be created and a notification sent to
  the handler's Procest inbox and email
- AND the supplier SHALL see a success confirmation and the message in the thread
- AND a supplier SHALL NOT be able to message a case outside its scope

### Requirement: Handler Response and Immutable Thread

The system SHALL record handler responses as outbound messages, notify the supplier, and keep the
thread immutable.

#### Scenario: Handler response appears in the supplier thread

- GIVEN a handler responds to a supplier message in Procest
- WHEN the response is recorded
- THEN a `SupplierMessage` with `direction` = outbound SHALL be created and the supplier emailed
- AND the thread SHALL display messages chronologically with sender and timestamp
- AND existing messages SHALL remain immutable (no update path) for audit and compliance
