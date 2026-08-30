---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# burger-notifications Specification

## Purpose
Sends proactive, Dutch-language notifications to the burger at key lifecycle moments of a case: receipt confirmation with the statutory deadline, ingebrekestelling receipt explaining the dwangsom tariff, and payment confirmation. Each notification is rendered from a template and delivered via the dossiq notification-router (Nextcloud, email, and portal), queued asynchronously so an SMTP failure never blocks the underlying lifecycle operation.
## Requirements
### Requirement: Burger-notificatie van termijn-events (REQ-TERM-008)

The system SHALL send proactive, Dutch-language notifications to the burger at key lifecycle moments: receipt, extension, ingebrekestelling-receipt, and payment confirmation.

#### Scenario: Receipt confirmation with deadline toezegging

- **GIVEN** an aanvraag is registered and a `TermijnInstance` is created
- **WHEN** the ontvangstbevestiging is sent
- **THEN** the burger SHALL receive a Dutch message containing the case reference, wettelijke termijn, berekende einddatum, a portal status link, and contact info

#### Scenario: Ingebrekestelling receipt explains the dwangsom tariff

- **GIVEN** a valid ingebrekestelling is registered (`geldigheidStatus = geldig`)
- **WHEN** the ingebrekestelling-receipt notification is sent
- **THEN** the burger SHALL receive confirmation of the receipt date, the grace-period end date, dwangsom-tariff transparency (€23/day rising, max €1.442), and a statement that the dwangsom stops on beschikking

#### Scenario: Payment confirmation notification

- **GIVEN** a dwangsom is paid and `DwangsomUitbetaling.status = betaald`
- **WHEN** the payment-confirmation notification is sent
- **THEN** the burger SHALL receive a message with the bedrag, payment date, payment reference, and a confirmation that it was paid as automatic compensation for niet-tijdig-beslissen

#### Scenario: Notifications fire at the correct lifecycle moments without blocking

- **GIVEN** the lifecycle triggers emitted by termijn creation, extension, ingebrekestelling, and payment
- **WHEN** each trigger is consumed
- **THEN** the matching template SHALL be rendered and delivered via the dossiq notification-router (Nextcloud + email + portal)
- **AND** delivery SHALL be queued asynchronously so an SMTP failure does not block the lifecycle operation

