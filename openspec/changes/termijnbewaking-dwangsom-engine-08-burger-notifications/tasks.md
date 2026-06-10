# Tasks: termijnbewaking-dwangsom-engine-08-burger-notifications

Member 8 of 11 (code). Depends on member 07. Traces to giant Tasks 14, 15 (REQ-TERM-008).

## 1. Templates (Dutch)

- [~] Template "ontvangstbevestiging": zaak-ref, wettelijke termijn, berekende einddatum, portal link, contact — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Template "extension-notification": new deadline + extension-brief copy — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Template "ingebrekestelling-receipt": confirmation date, grace-end date, dwangsom-tariff transparency, beschikking-stop statement — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Template "dwangsom-payment-notification": bedrag, payment date, payment reference, confirmation — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. NotificationService + delivery

- [~] Implement `NotificationService.sendTermijnNotification(type, termijnInstanceId, recipientUserId)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Render template with case-specific data (zaak-ref, dates, amounts) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Deliver via procest notification-router (Nextcloud notificatie + email + portal) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Route user-facing strings through the en/nl translation layer (no hardcoded copy) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Log all sends to the audit trail — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Lifecycle wiring

- [~] Consume the ontvangstbevestiging trigger from member 02 (on create) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Consume the extension trigger from member 03 — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Consume the ingebrekestelling-receipt trigger from member 05 — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Consume the payment trigger from member 07 — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement async notification queue (non-blocking on SMTP failures) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Tests

- [~] Unit test: template rendering with case data + recipient resolution — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test: each lifecycle trigger dispatches the correct template; async queue drains — deferred to downstream cycle / fleet-wide adoption (handoff)
