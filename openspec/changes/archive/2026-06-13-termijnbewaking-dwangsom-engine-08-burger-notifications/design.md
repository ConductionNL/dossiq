# Design: termijnbewaking-dwangsom-engine-08-burger-notifications

## Scope of this member

The burger notification templates + the `NotificationService` that renders and delivers them, plus the lifecycle wiring that consumes the triggers emitted by earlier members. No new domain state.

## Approach

- **Templates** (Dutch, ADR-007): `ontvangstbevestiging` (zaak-ref, wettelijke termijn, berekende einddatum, portal link, contact), `extension-notification` (new deadline + brief copy), `ingebrekestelling-receipt` (confirmation date, grace-end date, dwangsom-tariff transparency, beschikking-stop statement), `dwangsom-payment-notification` (bedrag, datum, referentie, confirmation).
- `NotificationService.sendTermijnNotification(type, termijnInstanceId, recipientUserId)` renders the template with case-specific data and delivers via the procest notification-router (Nextcloud notificatie + email + portal message); logs each send to the audit trail.
- **Lifecycle wiring**: consume the triggers already emitted by member 02 (ontvangstbevestiging on create), member 03 (extension), member 05 (ingebrekestelling-receipt), member 07 (payment). An async notification queue prevents blocking on SMTP failures.

## Security (ADR-005)

Recipient resolution is server-side (case → burger contact); templates render only data the recipient is entitled to. No client-supplied recipient or message body.

## i18n (ADR-007)

Messages ship in Dutch (the primary citizen language) with the en/nl translation scaffolding the app already carries; user-facing strings go through the translation layer, not hardcoded.

## Tests

Unit: template rendering with case data; recipient resolution. Integration: each lifecycle trigger dispatches the correct template through the router; async queue drains.
