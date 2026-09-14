# Tasks: inbound-mail-filters

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 25, candidates
C-intake-1, C-intake-11, C-intake-19, C-intake-29, C-intake-31,
C-intake-26 and C-intake-48. Decision D12, answered for Nextcloud Mail.
Waits on nothing: Nextcloud Mail already holds the account and the OAuth
2.0 flow.

- [ ] 1.1 `lib/Service/Email/NextcloudMailGateway.php`: the one class that
  names an `OCA\Mail` symbol, guarded by `IAppManager`, wrapping
  `AccountService`, `MailManager` (`getMailboxes`, `moveMessage`,
  `getSource`, `getByMessageId`, `getMailAttachments`), `DkimService` and
  `TrustedSenderService` (D-1).
  - `tests/unit/Service/Email/NextcloudMailGatewayTest.php`
  - `@spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md`
- [ ] 1.2 `lib/BackgroundJob/InboundEmailJob.php`: stop calling
  `imap_open()`, listen for `OCA\Mail\Events\NewMessagesSynchronized`, and
  read the account an administrator picked (D-2).
- [ ] 1.3 `src/views/settings/EmailSettings.vue`: replace the host,
  username and password fields with an account picker.
- [ ] 1.4 A migration that deletes `email_imap_password`,
  `email_imap_username` and `email_imap_host` (D-2).
  - `tests/unit/Migration/`
- [ ] 2.1 `lib/Service/Email/Filters/`: the named, ordered pipeline with
  one class per filter, each answering `accept`, `reject`, `quarantine`,
  `forward` or `pass`, and an accept default that is written down (D-3).
  - `tests/unit/Service/Email/FilterPipelineTest.php`
- [ ] 2.2 The first filters: auto-reply, bounce notification, own
  notification loop, and out of office (D-3).
- [ ] 3.1 `bounce` and `move` as named acts, with the forwarding address
  and the reason recorded, no case created and no rejection sent (D-4).
  - `tests/unit/Service/Email/BounceActionTest.php`
- [ ] 3.2 `lib/Service/Email/UnmatchedMailIntake.php`: an unmatched
  message lands in the intake inbox with its verdict, never dropped (D-5).
- [ ] 4.1 `lib/Service/Email/AuthenticationVerdict.php`: SPF and DMARC
  read from the authentication-results header on
  `MailManager::getSource()`, DKIM from `DkimService`, each `pass`,
  `fail`, `none` or `unavailable`, and `unavailable` never rendered as
  `pass` (D-6).
  - `tests/unit/Service/Email/AuthenticationVerdictTest.php`
- [ ] 4.2 The threading check through `MailManager::getByMessageId()`, and
  the rule that a `fail` threading result stops a subject-tag link (D-6).
  - `tests/unit/Service/Email/ThreadingCheckTest.php`
- [ ] 5.1 `caseType.intakePolicy`: `accept`, `quarantine` or `refuse`,
  defaulting to `quarantine`, with release by a named role recorded (D-7).
  - `tests/unit/Service/Email/IntakePolicyTest.php`
- [ ] 6.1 The intake log as a surface: original, verdict, deciding filter,
  outcome, readable by the intake role only, obeying the case type's
  retention (D-8).
  - `tests/vitest/intakeLog.spec.js`
- [ ] 6.2 The allow half read from `TrustedSenderService` and the block
  half held in dossiq, with blocking scoped to opening a case (D-9).
- [ ] 6.3 A junk verdict naming its rule, and a person able to correct it
  (D-9, D-10).
- [ ] 7.1 Dutch and English strings; a docs page saying that server-side
  Sieve filtering stays available and that a message filtered there never
  reaches this pipeline (D-10).
- [ ] 7.2 `tests/e2e/inbound-mail-filters.spec.ts`: an auto-reply, a
  bounce, a forged `In-Reply-To` against another person's case tag, a
  quarantined bezwaar released by hand, an unmappable message in the
  inbox, and the log found by sender;
  `openspec validate inbound-mail-filters --strict`.
