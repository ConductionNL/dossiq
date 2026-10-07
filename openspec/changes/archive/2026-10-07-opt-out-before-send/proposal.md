# Ask integriq before you message a citizen about a case

Part of the hydra change `opt-out-before-send` (ConductionNL/hydra#739). That change holds the fleet contract, the sender table and Ruben's decisions of 2026-10-05. integriq's side is ConductionNL/integriq#2530. This is dossiq's share.

## Why

dossiq sends to citizens on two paths, and neither asks whether the person opted out.

- **Case mail.** `CaseEmailService::sendEmail()` (`lib/Service/CaseEmailService.php:119`) checks only the recipient allow-list (`:149`) and then sends (`:340`). `sendFromTemplate()` (`:368`) goes the same way. Routes: POST `/api/email/{caseId}/send` and `/send-template` (`appinfo/routes.php:755-756`).
- **Digital post.** `BerichtenboxService::sendMessage()` (`lib/Service/BerichtenboxService.php:81`) hands the letter to integriq through `IntegriqAdapter::sendMessage()` (`lib/Service/BerichtenboxAdapter/IntegriqAdapter.php:120`). integriq sends it without a check today.

A citizen who clicked an integriq unsubscribe link still gets both.

## What changes

- **Case mail asks first.** One ask through integriq's `OutboundSendDecisionRequestedEvent`, resolved with `FleetAppId::resolveClass()` as the digital post adapter already does (`IntegriqAdapter.php:127-135`). An opted-out recipient gets no mail and the handler sees why.
- **Digital post passes a category.** dossiq does no check of its own here. integriq checks inside its digital post listener, which is the one choke point. dossiq passes `case-update` or `besluit` on the event, and already reads a refusal (`IntegriqAdapter.php:245-246`).
- **Every case mail carries the link** unless it is exempt.
- **The handler can mark a mail as a besluit.** The default is `case-update`. A template can carry its own category.

## Not a sender, so out of scope

- `BounceAction::forward()` (`lib/Service/Email/BounceAction.php:163`) is the Awb 2:3 doorzendplicht. It forwards to another administrative body, not to a citizen.
- `TermijnNotificationService` routes through `BerichtenboxRoutingService::routeToBerichtenbox()` (`lib/Service/BerichtenboxRoutingService.php:63-87`). That method logs and returns a derived id. It sends nothing today. When it gets a transport, it goes through digital post and integriq's check.
- `TenantWelcomeMailer` mails a tenant administrator.
- Flow mail through OpenRegister's `send-email` step is checked inside OpenRegister (ConductionNL/openregister#4334). `FlowEmailSentListener` then records only what was sent.

## Capabilities

### New capabilities

- `case-message-opt-out`: dossiq asks integriq before it messages a citizen, and says why when it may not.

## Impact

- `lib/Service/CaseEmailService.php`, `lib/Controller/EmailController.php`
- `lib/Service/BerichtenboxService.php`, `lib/Service/BerichtenboxAdapter/IntegriqAdapter.php`, `lib/Controller/BerichtenboxController.php`
- A new `lib/Service/OptOutGate.php` shared by both.
- The `emailTemplate` schema in `lib/Settings/dossiq_register.json` gains an optional `messageCategory`.
- The send dialogs show a refusal and a "this is a besluit" choice.
- Feature tier: V1.
- Pipelinq's request-to-case bridge does not send mail and is not affected.

## Reuse

ADR-011 check: no address or phone normalisation in dossiq. integriq normalises inside its listener. `FleetAppId` already resolves integriq's id across the rename, so no app id is hard-coded.

## Rollback

`IAppConfig` key `dossiq.outbound_optout_check` (default `true`). Off restores today's case mail. Digital post follows integriq's own switch.
