# Tasks: termijnbewaking-dwangsom-engine-08-burger-notifications

Member 8 of 11 (code). Depends on member 07. Traces to giant Tasks 14, 15 (REQ-TERM-008).

## 1. Templates (Dutch)

- [ ] Template "ontvangstbevestiging": zaak-ref, wettelijke termijn, berekende einddatum, portal link, contact
- [ ] Template "extension-notification": new deadline + extension-brief copy
- [ ] Template "ingebrekestelling-receipt": confirmation date, grace-end date, dwangsom-tariff transparency, beschikking-stop statement
- [ ] Template "dwangsom-payment-notification": bedrag, payment date, payment reference, confirmation

## 2. NotificationService + delivery

- [ ] Implement `NotificationService.sendTermijnNotification(type, termijnInstanceId, recipientUserId)`
- [ ] Render template with case-specific data (zaak-ref, dates, amounts)
- [ ] Deliver via procest notification-router (Nextcloud notificatie + email + portal)
- [ ] Route user-facing strings through the en/nl translation layer (no hardcoded copy)
- [ ] Log all sends to the audit trail

## 3. Lifecycle wiring

- [ ] Consume the ontvangstbevestiging trigger from member 02 (on create)
- [ ] Consume the extension trigger from member 03
- [ ] Consume the ingebrekestelling-receipt trigger from member 05
- [ ] Consume the payment trigger from member 07
- [ ] Implement async notification queue (non-blocking on SMTP failures)

## 4. Tests

- [ ] Unit test: template rendering with case data + recipient resolution
- [ ] Integration test: each lifecycle trigger dispatches the correct template; async queue drains
