# Tasks — Email-to-case matching via the OpenRegister email leaf

## Phase 1: Recognizer and resolution

- [x] `lib/Service/Email/CaseNumberRecognizer.php`: pattern loading + validation (compile check,
      >= 1 capture group; refuse the run on invalid, REQ-ECM-001), `extractCaseNumberCandidates()`
      over a text, and `resolveCases()` doing an exact `identifier` equality lookup on the
      configured register's `case` schema through the OpenRegister object surface.
  - it lives in its own class, not in `CaseEmailMatchService`, because that is the D6 seam: the
    recognizer is the only dossiq-specific part when the transport moves into the leaf
  - the capture-group check asks PCRE rather than reading the pattern: the body is wrapped as
    `(?:body)|` so it always matches, and PREG_UNMATCHED_AS_NULL then reports every group
- [x] Default pattern per design D2, app-config key `email_case_matching_pattern`; the key is in
      `ConfigKeys::ALL` (which is `SettingsService::CONFIG_KEYS`) and in `EmailSettings::MANAGED_KEYS`.
- [x] Fail-closed guards mirroring pipelinq `EmailMatchService::registerSlug()`: empty `register`
      app-config or unresolvable `case` schema -> zero OR calls, one logged warning (REQ-ECM-007).
  - and one pipelinq did not have: a slug register is never cast, because `(int)'dossiq'` is 0 and
    a link to register 0 is a write to the wrong place rather than a refusal

## Phase 2: Message discovery and linking

- [x] Message iteration lifted from pipelinq's shape, in `lib/Service/Email/MailMessageSource.php`:
      `mail_messages` join `mail_mailboxes` on the configured account, `id > cursor`, ASC, batch cap
      200; subject from `mail_messages.subject`; body from `preview_text` only when the subject
      resolves nothing (REQ-ECM-002, design D3).
  - V1 reads the cached preview and no further. Mail caps it at 255 characters, so a case number
    deeper in a long body is missed, and the settings screen says so rather than leaving a user to
    assume otherwise
  - the account is checked against `mail_accounts.user_id` before it is read: an account id in a
    setting is not proof of ownership, and the leaf does not check it either
- [x] Link via the leaf only: `OCA\OpenRegister\Service\EmailLinkService` resolved through the DI
      container with `method_exists` guards; `getLinkedEmails` pre-check per case, then `linkEmail(...)`;
      every distinct resolved case linked, per-case failures logged and non-fatal (REQ-ECM-003,
      REQ-ECM-004). No dossiq-local link table.
- [x] No-match and no-resolution paths write nothing and create nothing (REQ-ECM-005).

## Phase 3: Job, toggles, settings surface

- [x] `lib/BackgroundJob/CaseEmailMatchJob.php` (`TimedJob`, 300 s), registered in `appinfo/info.xml`;
      it visits only opted-in users; per-user cursor and last-run status (timestamp, scanned, linked,
      last error) in `lib/Service/Email/CaseEmailMatchPreferences.php` (REQ-ECM-008).
  - the store is the user's Nextcloud preferences, not `IAppConfig` keyed `<prefix>.<uid>` as
    pipelinq keeps it: app-config keys are capped at 64 characters and a user id may be 64 on its
    own, so that shape throws for a long LDAP or SSO id. See the design note
  - so the job asks `searchUsersByValueBool()` for opted-in users instead of walking every account
- [x] Instance toggle `email_case_matching_enabled` (default `no`) through `SettingsService`; per-user
      settings (enabled default `false`, mail account id) with read/write endpoints and a settings
      section showing the account picker, the switch and the last run (REQ-ECM-006).
  - the per-user section is on the PERSONAL settings page, which is where a per-user setting belongs;
    the instance toggle and the pattern are on the admin email settings page
  - both live on `/api/settings/email-case-matching`; the instance pair has its own admin endpoints
    rather than joining `EmailTemplateController`'s IMAP keys, because the pattern is validated
    before it is stored
  - `templates/settings/personal.php` referenced `OCA\Procest\AppInfo\Application`, which has not
    existed since the rename, so the whole personal settings page answered 500 (confirmed live, and
    in nextcloud.log). Fixed here, because the new section sits on that page
- [x] Cursor initialises at the account's current max `mail_messages.id` on first enable (no historic
      scan), and restarts there when the account changes.
  - `-1` means "never started", so `0` stays a real starting point: an account that held no mail

## Phase 4: Tests and verification

- [x] Unit tests: recognizer (bare / prefixed / bracketed / boundary-guarded negatives / invalid
      pattern refusal), subject-first ordering, multi-case linking, idempotent re-run, no-match no-op,
      unresolvable-candidate skip, empty-register refusal, instance/user toggle gating,
      poisoned-message continuation. Plus the ones the security reading added: a foreign mail account,
      a case the owner may not read, an identifier that resolves twice, a loose search hit, a slug
      register, and that resolution and linking both happen as the mailbox owner.
  - 37 PHP mutations and 5 Vue mutations, each one watched to redden the right test and then restored
  - one of them earned its keep: an "auto-create on a miss" mutation left both no-match tests GREEN,
    because the fake object service threw and the per-message catch swallowed it. The fake now
    RECORDS writes and the tests assert zero. A test that passes when the matcher writes on a
    no-match is worth nothing, and that is exactly what those two were until this was found
- [x] Dev-environment smoke on localhost:8080 (NC 34, mail 5.9.3, openregister 2.0.18): toggle off ->
      job did nothing; toggle on -> 3 scanned, 1 linked, the link visible on case `2026-0003` through
      the leaf with `linkedBy admin`, register 23, schema 172; replayed from the same cursor -> 0
      linked and still exactly 1 link. The two non-matching messages left no link row anywhere.
  - the messages were inserted into Mail's own tables rather than delivered over SMTP and synced.
    That is the table the matcher reads, but it is a simulated arrival, not a delivered mail
  - everything created was removed afterwards and the removal verified: links, messages, the toggle,
    the preferences
- [x] `grep` for forbidden debug helpers (gate-3, green), `php -l` on all 15 changed PHP files,
      hydra gates green, and `openspec validate --strict`.

## Phase 5: The shared mailbox stops losing mail (REQ-ECM-009, REQ-ECM-010)

Added after the change was written. Phases 1 to 4 describe the per-user NC Mail
matcher and are untouched by this phase: they remain open. This phase is the
shared functional mailbox, which `InboundEmailJob` already polls, and it revises
design decision D4 for that source only.

- [x] 5.1 `lib/Service/AssigneeResolver.php`: the `{{ case.assignee }}` plus
  `assigneeFallback` rule, lifted out of `DossiqAskPersonNode` so it has one
  implementation rather than three.
  - the node keeps the refusal, which is genuinely its own
  - unit test `tests/Unit/Service/AssigneeResolverTest.php`
- [x] 5.2 `CreateTaskHandler` resolves through it, adds the case's own handler
  as a third source, and carries the case's team into `assigneeGroup`.
  - it creates and warns rather than refusing, because a transition side effect
    that refuses aborts a status change
- [x] 5.3 `StatusChecklist` names its assignee in the same spelling instead of
  emitting an action that names nobody.
- [x] 5.4 `InboundEmailJob`: resolve the subject tag to the bare identifier and
  look it up through `CaseEmailRepository::findCaseIdByIdentifier()`. The tag
  was previously passed on whole, so the matched path never matched.
  - unit test `tests/Unit/BackgroundJob/InboundEmailJobTest.php`, the job's first
- [x] 5.5 `lib/Service/Email/UnmatchedMailIntake.php`: an unmatched mail becomes
  a case of the configured fallback type, and nothing at all when none is
  configured.
  - unit test `tests/Unit/Service/Email/UnmatchedMailIntakeTest.php`
- [x] 5.6 The run reports what it could not place, at warning when a fallback
  type is configured and at info when none is.
- [x] 5.7 `email_fallback_case_type` in `EmailTemplateController::IMAP_KEYS` and
  `EmailSettings::MANAGED_KEYS`, and a case type picker in
  `src/views/settings/EmailSettings.vue` defaulting to leaving the mail alone.
- [x] 5.8 Every new guard mutation-checked: the opt-in guard, the prefix
  stripping and the assignee resolution were each removed, the right assertions
  watched to fail, and the file restored and diffed against a backup.

## Built 2026-09-11

Phases 1 to 4 are implemented, so the two re-verification notes below (which said
`CaseEmailMatchService.php` and `CaseEmailMatchJob.php` had never existed) are now history rather
than status. They were right when written.

The one part they said could not be finished at a desk, the dev-environment smoke, was done on the
shared instance at localhost:8080. It needed a live Nextcloud with Mail and OpenRegister, which
that instance has, and the mail was simulated by writing Mail's own cache rows rather than
delivered over SMTP. Phase 4 records what it showed.

Left for a follow-up, deliberately:

- the E2E surface. The matcher is a background job over Mail's tables, so there is nothing for
  Playwright to drive; the personal settings section has a vitest instead. No `@e2e` citation is
  claimed for it.
- design D6, lifting the transport into the leaf. The seam is kept clean and the split now makes
  it mechanical, but it is an openregister change and not this one.

---

## Re-verified 2026-09-09

Still phase 5 only. `CaseEmailMatchService.php` and `CaseEmailMatchJob.php` do not exist and
never have: `git log --all` on both paths is empty, so this is unbuilt rather than removed.
Nothing under `lib/Service/Email/` or `lib/BackgroundJob/` matches. The design holds and the
work is unstarted; this is backlog, not in flight.

## Re-verified 2026-09-10

Unchanged, and one thing worth adding for whoever picks it up: **the dependency is in place.**
Phase 2 links through OpenRegister's `EmailLinkService`, and
`ConductionNL/openregister` carries `lib/Service/EmailLinkService.php`. So phases 1 to 4 are
unstarted rather than blocked. The one part that cannot be finished at a desk is the phase 4
dev-environment smoke, which needs a live instance with the mail app and a real message.
