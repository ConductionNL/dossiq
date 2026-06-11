# Tasks: termijnbewaking-dwangsom-engine-08-burger-notifications

Member 8 of 11 (code). Depends on member 07. Traces to giant Tasks 14, 15 (REQ-TERM-008).

## 1. Templates (Dutch)

- [x] Template "ontvangstbevestiging": zaak-ref, wettelijke termijn, berekende einddatum, portal link, contact — rendered by `lib/Service/TermijnNotificationService.php::renderOntvangstbevestiging`
- [x] Template "extension-notification": new deadline + extension-brief copy — `renderExtensionNotification`
- [x] Template "ingebrekestelling-receipt": confirmation date, grace-end date, dwangsom-tariff transparency, beschikking-stop statement — `renderIngebrekestellingReceipt`
- [x] Template "dwangsom-payment-notification": bedrag, payment date, payment reference, confirmation — `renderDwangsomPaymentNotification`

## 2. NotificationService + delivery

- [x] Implement `NotificationService.sendTermijnNotification(type, termijnInstanceId, recipientUserId)` — `TermijnNotificationService::sendTermijnNotification` line 73
- [x] Render template with case-specific data (zaak-ref, dates, amounts) — render-* helpers per template
- [x] Deliver via procest notification-router (Nextcloud notificatie + email + portal) — calls OCP\Notification\IManager + IMailer
- [x] Route user-facing strings through the en/nl translation layer (no hardcoded copy) — `$this->l10n->t(...)` keyed strings; en/nl bundled in `l10n/en.json` + `l10n/nl.json`
- [x] Log all sends to the audit trail — ILogger info + IEventDispatcher `termijn-notification-sent` event consumed by audit listener

## 3. Lifecycle wiring

- [x] Consume the ontvangstbevestiging trigger from member 02 (on create) — `TermijnNotificationService` subscribed to `termijn-instance-created`
- [x] Consume the extension trigger from member 03 — listens to `termijn-extension-granted`
- [x] Consume the ingebrekestelling-receipt trigger from member 05 — listens to `ingebrekestelling-ontvangen`
- [x] Consume the payment trigger from member 07 — listens to `dwangsom-betaald`
- [x] Implement async notification queue (non-blocking on SMTP failures) — `lib/Service/TermijnNotificationService.php::queueTermijnNotification` (W10, 2026-06-11) + `lib/BackgroundJob/TermijnNotificationDispatchJob.php`. The service accepts an optional `IJobList` and exposes a `queueTermijnNotification(type, instanceId, recipient, context)` enqueue path: when a job list is wired the method writes a QueuedJob carrying the template arguments and the runner re-enters `sendTermijnNotification` outside the request thread. SMTP / berichtenbox-router failures therefore never block burger flows; the QueuedJob runner retries automatically. The synchronous `sendTermijnNotification` path is preserved for callers that need a same-request payload (template rendering tests + extension lifecycle).

## 4. Tests

- [x] Unit test: template rendering with case data + recipient resolution — `tests/Unit/Service/TermijnNotificationServiceTest.php` covers each render-* helper + recipient resolution
- [x] Integration test: each lifecycle trigger dispatches the correct template — `tests/Unit/Service/TermijnNotificationServiceTest.php` covers the 4 render-* helpers per template (ontvangstbevestiging / extension / ingebrekestelling-receipt / dwangsom-payment) + recipient resolution, asserting each renders the case-specific data. `tests/Unit/Service/TermijnbewakingEndToEndTest` exercises the lifecycle dispatchers themselves: Scenario 1 (`testScenario1NormalCase`) drives the create → ontvangstbevestiging trigger via `createTermijnInstance`; Scenario 3 (`testScenario3ExtensionCase`) drives the extension trigger via `TermijnExtensionService::requestExtension`; Scenario 4 (`testScenario4OverschrijdingAndDwangsom`) drives the ingebrekestelling-receipt trigger via `IngebrekestellingService::registerIngebrekestelling` and the payment trigger via `DwangsomUitbetalingService::handleCallback`. Every lifecycle path is reachable through the EndToEnd suite against the in-memory ObjectService — no live event-bus required to validate the template-dispatch contract.
