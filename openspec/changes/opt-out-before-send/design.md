# Design: ask integriq before you message a citizen about a case

The fleet contract, the category list, the fail mode and Ruben's decisions of 2026-10-05 live in hydra's `openspec/changes/opt-out-before-send/design.md` (ConductionNL/hydra#739). This file covers dossiq. Lines are at `development` `d51b74b9`.

## 1. One gate for dossiq

A new `OptOutGate` service in `lib/Service/` owns the question for case mail.

- It resolves the event class with `FleetAppId::resolveClass(canonical: 'integriq', relative: 'Event\\OutboundSendDecisionRequestedEvent')`, the way `IntegriqAdapter` resolves the digital post event (`lib/Service/BerichtenboxAdapter/IntegriqAdapter.php:127-135`). So integriq's id is never hard-coded, and the rename keeps working.
- It dispatches with `sourceApp: 'dossiq'`, channel `email`, the category, the recipient and `caseRef` set to the case id. The case ref is what makes integriq's case-scoped opt-out apply: the existing unsubscribe link stops one case's updates (`integriq/lib/Controller/SenderIdentityController.php:252`).
- Class missing, event unhandled, or the listener throws: a non-exempt mail is refused with `authority-unavailable` and logged at warning level with the case and the category (decision 1). An exempt mail (`besluit`, `statutory`) is sent without a link.
- The config key `dossiq.outbound_optout_check` (default `true`) turns the gate off. It is security relevant (ADR-102), so anything other than `false` reads as on.

## 2. Case mail

`CaseEmailService::sendEmail()` (`lib/Service/CaseEmailService.php:119`):

1. Loads the case under RBAC, as now.
2. Checks the recipient allow-list (`assertRecipientAllowed`, `:149`), as now.
3. **New:** asks `OptOutGate`. On `send: false` it throws `RecipientOptedOutException` with the code. Nothing is sent and nothing is recorded as sent.
4. **New:** appends integriq's link line to the HTML body and sets `List-Unsubscribe` headers when the mailer exposes them. The headers go through OpenRegister's shared `UnsubscribeHeaders` helper (ConductionNL/openregister#4334, REQ-ERO-005), which Ruben chose on 2026-10-05. dossiq keeps no copy of the guarded path.
5. Sends (`:340`) and records, as now.

`sendEmail()` gains an optional `string $category = 'case-update'`. `sendFromTemplate()` (`:368`) reads the template's `messageCategory`, falling back to `case-update`.

`EmailController::send()` and `::sendFromTemplate()` catch only `RuntimeException` today (`lib/Controller/EmailController.php:86`, `:123`). They gain a catch for `RecipientOptedOutException` that answers 409 with `error: opted-out`, `no-consent` or `authority-unavailable` (ADR-105). The request body may carry `category`. Only `case-update` and `besluit` are accepted from a handler. Anything else is a 400.

## 3. Digital post

dossiq does not ask integriq separately here. integriq's digital post listener is the one choke point (integriq change, section 3). Asking twice would double the audit and add nothing.

- `BerichtenboxController` reads a new optional `category` parameter next to `berichtTypeCode` (`lib/Controller/BerichtenboxController.php:78`). Default `case-update`. Accepted: `case-update`, `besluit`, `statutory`.
- `BerichtenboxService::sendMessage()` (`lib/Service/BerichtenboxService.php:81`) and the adapter interface pass it on.
- `IntegriqAdapter::sendMessage()` builds `DigitalPostSendRequestedEvent` positionally with eight arguments today (`IntegriqAdapter.php:162-171`). integriq adds `category` as an optional ninth constructor argument, so the current call keeps working and the new one passes it.
- A refusal is already read and returned through `getRefusal()` (`:245-246`). The refusal code `opted-out` reaches the UI the same way `unknown_source` does now.

## 4. What the handler sees

- The case mail dialog has a checkbox: "This is a besluit (always sent, no unsubscribe link)". Unchecked means `case-update`.
- A refusal shows inline: "This person asked not to receive updates about this case. Nothing was sent." For `authority-unavailable`: "integriq is not available, so dossiq cannot check whether this person may be mailed. Nothing was sent."
- Strings are added in English and Dutch.

## 5. Schema

`emailTemplate` in `lib/Settings/dossiq_register.json` (`:11036`) gains an optional `messageCategory` string with the enum `case-update`, `besluit`, `statutory`. Adding a property with an enum to an existing schema is additive. No `format` is added (adding a `format` is a breaking change in OpenRegister).

## 6. Not touched

- `BounceAction`, `TermijnNotificationService`, `TenantWelcomeMailer`: see the proposal.
- `FlowEmailSentListener`: OpenRegister skips opted-out addresses before it raises `FlowEmailSentEvent`, so the listener only records real sends.

## 7. Found while reading

**Termijn notifications reach nobody.** `TermijnNotificationService::sendTermijnNotification()` (`lib/Service/TermijnNotificationService.php:159`) renders the notification and hands it to `BerichtenboxRoutingService::routeToBerichtenbox()` (`:180`). That method resolves a channel, logs "beschikking gerouteerd", and returns a message id derived from a hash of the reference (`lib/Service/BerichtenboxRoutingService.php:63-87`). No transport is called. The caller stores that record "as proof of dispatch" (`TermijnNotificationService.php:176-179`), so the case shows a notification as sent that was never sent. This is outside the opt-out change and needs its own change. When it gets a real transport, it goes through digital post (section 3), as `statutory` for an ontvangstbevestiging.
