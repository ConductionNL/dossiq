---
kind: code
depends_on:
  - leverancier-zaakportaal-04-supplier-scope-security
chain:
  - leverancier-zaakportaal-01-schema-foundation
  - leverancier-zaakportaal-02-eherkenning-auth
  - leverancier-zaakportaal-03-rbac-user-management
  - leverancier-zaakportaal-04-supplier-scope-security
  - leverancier-zaakportaal-05-tender-backend
  - leverancier-zaakportaal-06-tender-frontend
  - leverancier-zaakportaal-07-invoice-backend
  - leverancier-zaakportaal-08-invoice-frontend
  - leverancier-zaakportaal-09-contract-backend
  - leverancier-zaakportaal-10-contract-frontend
  - leverancier-zaakportaal-11-messaging
  - leverancier-zaakportaal-12-master-data-mutations
  - leverancier-zaakportaal-13-kpi-backend
  - leverancier-zaakportaal-14-kpi-frontend
  - leverancier-zaakportaal-15-dashboard-shell
  - leverancier-zaakportaal-16-tests-and-docs
---

# Proposal: leverancier-zaakportaal — Member 11: Secure Per-Case Messaging (code)

Member **11 of 16**. Depends on member 04 (scope). Implements per-case messaging end-to-end:
message service, routing job to the Procest handler inbox, handler-response inbound, and the Vue
composer/thread UI. Messages are immutable (audit trail) per the write-once `SupplierMessage`
schema from member 01.

## Why

Suppliers ask questions about specific cases; handlers reply; everything is audit-logged. This is
a self-contained slice (service + job + UI) small enough to ship together (~14 tasks).

## What Changes

1. `SupplierMessageService` — sendMessage, addResponse, getConversationHistory.
2. `MessageController` + `RouteSupplierMessageJob` — notify handler inbox + email.
3. `MessageComposer` + `MessageThread` + `MessageBubble` Vue components.

## Out of Scope (this member)

The Procest handler-side inbox UI (Procest). Notification email transport is shared infra.

## Dependencies

- **leverancier-zaakportaal-04-supplier-scope-security** (REQUIRED) — scope + middleware

## Traceability

Derives from giant tasks 3.6 (message service) and 2.5 (messaging interface); spec REQ-006.
