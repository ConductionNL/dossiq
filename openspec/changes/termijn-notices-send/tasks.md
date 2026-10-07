# Tasks: termijn-notices-send

- [x] 1.1 Tests first through the real path: PauseChaseService to TermijnNotificationService to TermNoticeSender to OptOutGate. Two triggers mail once, an opted-out person gets nothing and it is logged, the ontvangstbevestiging is statutory, a missing integriq refuses and retries later.
  - files: `tests/Unit/Service/Termijn/TermNoticeDeliveryTest.php`, `tests/Support/InMemoryTermNoticeLedger.php`
  - test: `vendor/bin/phpunit --no-coverage --filter TermNoticeDeliveryTest`
- [x] 1.2 `TermNoticeSender` and `TermNoticeLedger`, the `dossiq_term_notices` migration, and the version bump.
  - spec_ref: `specs/burger-notifications/spec.md#requirement-a-term-notice-is-mailed-after-integriq-allows-it-req-term-070`, `#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071`
- [x] 1.3 `TermijnNotificationService` sends through it; `PauseChaseService` names each reminder.
- [ ] 1.4 Live: a reminder due on a paused term is mailed once to a normal citizen and not to one who opted out for `service`.
