## 1. Build

- [ ] 1.1 `handlePauseExpiry()` in `lib/Listener/TermijnTimerFiredListener.php` resumes a still-paused term through `lib/Service/TermijnTimerService.php` and records the `hervat` event (REQ-TERM-080); today it only records `pause-expired` and leaves the term paused.
- [ ] 1.2 A notification rule for the handler under `x-openregister-notifications`, with the case and new end date.
- [ ] 1.3 Keep the still-paused guard, so a helper firing after an earlier resume does nothing.
- [ ] 1.4 Unit tests in `tests/Unit/Service/` for the expiry, the early resume and the notification.

## 2. Hand-over

- [ ] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
