# Tasks: opt-out-before-send (dossiq)

Spec only until Ruben approves ConductionNL/hydra#739. Build after integriq ships the events (ConductionNL/integriq#2530). All tasks are tier V1.

## 1. The gate

- [x] 1.1 Deduplication check: confirm no opt-out or consent reader exists in dossiq for citizen mail (`git grep -n -i "opt.out\|unsubscribe\|consent" lib/Service/CaseEmailService.php lib/Service/Berichtenbox*`). The `dossiq-sociaal-domein-avg-consent` spec is about processing consent, not messaging; confirm it does not overlap.
  - acceptance: the PR body lists the hits and the conclusion.
- [x] 1.2 `OptOutGate` with `FleetAppId` resolution, the fail mode and the config switch.
  - spec_ref: `specs/case-message-opt-out/spec.md#requirement-case-mail-asks-integriq-before-it-is-sent-req-coo-001`
  - files: `lib/Service/OptOutGate.php`, `tests/Unit/Service/OptOutGateTest.php`
  - acceptance: absent class, unhandled event and a throwing listener refuse `case-update` and pass `besluit`. The test constructs a real event class stub with the integriq shape and a real dispatcher.
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutGateTest`

## 2. Case mail

- [x] 2.1 `sendEmail()` and `sendFromTemplate()` ask after the allow-list and before the send. `RecipientOptedOutException`.
  - files: `lib/Service/CaseEmailService.php`, `lib/Exception/RecipientOptedOutException.php`, `tests/Unit/Service/CaseEmailServiceTest.php`
  - acceptance: an opted-out recipient gets no mail and no sent record. Red before.
  - test: `vendor/bin/phpunit --no-coverage --filter CaseEmailServiceTest`
- [x] 2.2 `EmailController` answers 409 with the code, and 400 on a category other than `case-update` or `besluit`.
  - spec_ref: `#requirement-a-handler-can-send-a-besluit-that-is-always-delivered-req-coo-002`
  - files: `lib/Controller/EmailController.php`, its test
  - test: `vendor/bin/phpunit --no-coverage --filter EmailControllerTest`
- [x] 2.3 The link line in the body and the headers.
  - spec_ref: `#requirement-every-non-exempt-case-mail-carries-the-unsubscribe-link-req-coo-003`
  - files: `lib/Service/CaseEmailService.php`
  - acceptance: headers are set through OpenRegister's `UnsubscribeHeaders`, injected, not copied.
  - test: `vendor/bin/phpunit --no-coverage --filter CaseEmailServiceTest`
- [ ] 2.4 `messageCategory` on `emailTemplate`, additive, no `format`.
  - files: `lib/Settings/dossiq_register.json`
  - acceptance: the register imports on a clean instance with no `PARTIAL IMPORT` in the log. Test with OpenRegister that the schema validates.
  - test: `occ maintenance:repair` on the dev instance, then `grep "PARTIAL IMPORT" data/nextcloud.log` finds nothing new

## 3. Digital post

- [x] 3.1 `category` from `BerichtenboxController` through `BerichtenboxService` and the adapter interface into the event.
  - spec_ref: `#requirement-digital-post-carries-a-category-to-integriq-req-coo-004`
  - files: `lib/Controller/BerichtenboxController.php`, `lib/Service/BerichtenboxService.php`, `lib/Service/BerichtenboxAdapter/*`
  - acceptance: an integriq without the ninth argument still accepts the call (integriq's change makes it optional).
  - test: `vendor/bin/phpunit --no-coverage --filter Berichtenbox`

## 4. The dialogs

- [ ] 4.1 The besluit checkbox and the refusal messages in the case mail and Berichtenbox dialogs. English and Dutch strings.
  - files: `src/` dialogs under `src/modals/`, `l10n/`
  - acceptance: a refusal is announced to a screen reader (`role="alert"`). WCAG 2.2 AA.
  - test: `npm run test:l10n`, then a Playwright check of the refusal on the dev instance

## 5. Follow-up found while reading

- [ ] 5.0 Open a separate change for termijn notifications: `BerichtenboxRoutingService::routeToBerichtenbox()` only logs and returns a derived id (design section 7). Not built here.

## 6. Verify

- [ ] 6.1 Live check: stop a case through integriq's link, send a case mail and a Berichtenbox case update about it. Both are refused. A besluit goes out.
- [ ] 6.2 Pipelinq bridge: create a case from a pipelinq request and confirm nothing in the bridge sends mail.
- [ ] 6.3 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` once, then `npm run lint`.
