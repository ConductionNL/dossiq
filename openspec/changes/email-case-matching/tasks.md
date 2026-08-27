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
