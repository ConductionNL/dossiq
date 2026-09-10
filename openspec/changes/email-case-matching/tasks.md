# Tasks — Email-to-case matching via the OpenRegister email leaf

## Phase 1: Recognizer and resolution

- [ ] Add `lib/Service/CaseEmailMatchService.php`: pattern loading + validation (compile check,
      ≥1 capture group; refuse the run on invalid — REQ-ECM-001), `extractCaseNumberCandidates()`
      over a text, and `resolveCasesByIdentifier()` doing an exact `identifier` equality lookup on
      the configured register's `case` schema through the OpenRegister object surface.
- [ ] Default pattern per design D2 (`(?<![\w-])(?:\[)?(?:[A-Z]{2,10}-)?((?:19|20)\d{2}-\d{4,6})(?:\])?(?![\w-])`),
      app-config key `email_case_matching_pattern`; add the key to `SettingsService::CONFIG_KEYS`.
- [ ] Fail-closed guards mirroring pipelinq `EmailMatchService::registerSlug()`: empty `register`
      app-config or unresolvable `case` schema → zero OR calls, one logged warning (REQ-ECM-007).

## Phase 2: Message discovery and linking

- [ ] Message iteration lifted from pipelinq's shape: `mail_messages` ⋈ `mail_mailboxes` on the
      configured account, `id > cursor`, ASC, batch cap 200; subject from `mail_messages.subject`;
      body text from the Mail store (cached preview, full body where cheaply retrievable) only when
      the subject resolves nothing (REQ-ECM-002, design D3).
- [ ] Link via the leaf only: resolve `OCA\OpenRegister\Service\EmailLinkService` through the DI
      container with `method_exists` guards (openregister + mail optional runtime deps); pre-check
      `getLinkedEmails` per case, then `linkEmail(objectUuid, registerId, schemaId, mailAccountId,
      messageId, messageUid)`; link every distinct resolved case, per-case failures logged and
      non-fatal (REQ-ECM-003, REQ-ECM-004). No dossiq-local link table.
- [ ] No-match and no-resolution paths write nothing and create nothing (REQ-ECM-005).

## Phase 3: Job, toggles, settings surface

- [ ] Add `lib/BackgroundJob/CaseEmailMatchJob.php` (`TimedJob`, 300 s), registered in
      `appinfo/info.xml`; iterate opted-in users; per-user cursor + last-run status blob (timestamp,
      scanned, linked, last error) in `IAppConfig` (REQ-ECM-008).
- [ ] Instance toggle `email_case_matching_enabled` (default `no`) via `SettingsService`
      (+ `CONFIG_KEYS`); per-user settings blob (enabled default `false`, mail account id) with
      read/write endpoints and a section in the email settings UI showing account selector, enable
      toggle, last-run status (REQ-ECM-006).
- [ ] Cursor initialises at the account's current max `mail_messages.id` on first enable (no
      historic scan).

## Phase 4: Tests and verification

- [ ] Unit tests: recognizer (bare / prefixed / bracketed / boundary-guarded negatives / invalid
      pattern refusal), subject-first ordering, multi-case linking, idempotent re-run, no-match
      no-op, unresolvable-candidate skip, empty-register refusal, instance/user toggle gating,
      poisoned-message continuation.
- [ ] Dev-environment smoke: enable both toggles, send a mail with `2026-0042` in the subject to the
      configured account, run the job, verify the link appears on the case's Mail surface; repeat
      run and verify no duplicate.
- [ ] `grep` the diff for forbidden debug helpers, `php -l` all touched files, run the hydra gates,
      and `openspec validate --strict` on this change.

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
