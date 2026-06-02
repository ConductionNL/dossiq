---
kind: code
depends_on: [termijnbewaking-dwangsom-engine-07-financial-integration]
chain:
  - termijnbewaking-dwangsom-engine-01-schemas-and-seed
  - termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle
  - termijnbewaking-dwangsom-engine-03-pause-extension
  - termijnbewaking-dwangsom-engine-04-daily-scan-escalation
  - termijnbewaking-dwangsom-engine-05-ingebrekestelling
  - termijnbewaking-dwangsom-engine-06-dwangsom-calculation
  - termijnbewaking-dwangsom-engine-07-financial-integration
  - termijnbewaking-dwangsom-engine-08-burger-notifications
  - termijnbewaking-dwangsom-engine-09-reporting-dashboard
  - termijnbewaking-dwangsom-engine-10-bezwaar-rest-api
  - termijnbewaking-dwangsom-engine-11-tests-admin-docs
---

# Proposal: termijnbewaking-dwangsom-engine-08-burger-notifications

Member 8 of 11 in the **termijnbewaking-dwangsom-engine** chain (ADR-032). Predecessor: `termijnbewaking-dwangsom-engine-07-financial-integration`. This `kind: code` member implements the burger-facing notification templates and wires them into the lifecycle moments the earlier members emit triggers for.

## Why

Transparent, proactive communication to the burger is both a legal expectation and the single biggest driver of trust at deadline moments: receipt-with-deadline, extension, ingebrekestelling-receipt (with dwangsom-tariff transparency), and payment confirmation. The earlier members already emit notification *triggers*; this member renders the Dutch-language messages and delivers them multi-channel. Per ADR-007 the messages are in Dutch (en/nl both supported by the app).

## What Changes (this member)

1. Notification templates (Dutch): ontvangstbevestiging, extension, ingebrekestelling-receipt, dwangsom-payment.
2. `NotificationService.sendTermijnNotification()` renders + delivers via the procest notification-router (Nextcloud + email + portal).
3. Lifecycle wiring: the triggers emitted by members 02/03/05/07 are consumed and dispatched; an async queue prevents blocking on SMTP failures.

## Impact

- **Affected**: procest (`NotificationService`, `TermijnNotificationTemplate`, notification-templates), nldesign-portal (renderer consumer).
- **Traces to giant tasks**: Task 14 (templates + system), Task 15 (lifecycle integration), REQ-TERM-008.
- **Depends on**: member 07 (payment trigger) and the triggers emitted by members 02/03/05.
