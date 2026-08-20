# Proposal: email-case-matching

kind: feature — cites **ADR-022** (apps-consume-or-abstractions / leaf-first), **ADR-012** (deduplication),
**ADR-019** (cross-app integration). Prior art: pipelinq `EmailMatchService` + `EmailMatchJob`
(`pipelinq/lib/Service/EmailMatchService.php`, 5-minute `TimedJob`, links Nextcloud Mail messages to CRM
records through OpenRegister's email leaf).

## Summary

When a procest case number appears in the subject or body of an email in a user's Nextcloud Mail
account, that email is automatically attached to the case through OpenRegister's email leaf
(`OCA\OpenRegister\Service\EmailLinkService`), idempotently, on a 5-minute `TimedJob`. The `case`
schema's `configuration.linkedTypes` already contains `"mail"`, so the Mail sidebar can link messages
to cases **manually** today — but there is no `mailObjectTemplate` and no matching job, so nothing
links automatically. This change adds the automatic path: a configurable case-number recognizer
(grounded in the schema's generated `identifier` format `YYYY-NNNN`, e.g. `2026-0042`), subject
scanned first then body, links to every matched case, never auto-creates a case, per-instance and
per-user enable toggles (both default off), and a fail-closed guard when the register is
unconfigured — mirroring pipelinq's guard exactly.

## Why

A case handler's correspondence about `2026-0042` lands in their NC Mail inbox and never reaches the
case file unless they remember to link it by hand in the Mail sidebar. Procest's only automatic
inbound-mail path today is `InboundEmailJob`, which polls one **shared functional IMAP mailbox** and
only recognises bracketed subject tags — personal mailboxes and untagged replies are invisible to the
case. Pipelinq has already proven the NC-Mail-tables + email-leaf pattern in production; procest needs
the same mechanism with a different recognizer: case-number tokens in text instead of correspondent
addresses.

## What

1. `CaseEmailMatchService` — reads `mail_messages` / `mail_mailboxes` (and nothing procest-local) for
   new messages per configured account, extracts case-number candidates from the subject (then the
   body text available from the Mail store when the subject has none), resolves each candidate against
   the `case` schema's `identifier` property via OpenRegister, and calls
   `EmailLinkService::linkEmail(...)` for every resolved case. Idempotent via the leaf's existing-link
   check plus a per-user message-id cursor.
2. `CaseEmailMatchJob` — `TimedJob`, 300 s, iterating users with matching enabled; per-message
   failures never abort a run.
3. Configuration — instance toggle `email_case_matching_enabled` (default `no`) via
   `SettingsService`; per-user settings blob (enabled + mail account) in `IAppConfig`, mirroring
   pipelinq's per-user `email_match_settings.<uid>` shape; configurable recognizer pattern
   `email_case_matching_pattern` with a validated default that matches the schema's real identifier
   format **and** the legacy bracketed tag (see design D2 — the existing `InboundEmailJob` pattern
   `/\[([A-Z]+-\d{4}-\d{4,6})\]/` does not match what the schema actually generates; this change must
   not repeat that mismatch).
4. Fail closed — when `SettingsService::getConfigValue('register')` is empty or the case schema is
   unresolvable, the matcher refuses to run (no writes, one log line); an empty register value is
   never cast to an id (pipelinq's `registerSlug()` guard convention).

## Capabilities

### Added Capabilities

- `email-case-matching` — automatic, idempotent attachment of NC Mail messages to cases by case-number
  recognition, through the OpenRegister email leaf.

## Affected Projects

- **procest** — new service, job, settings keys, per-user settings surface.
- **openregister** — no change required (the email leaf is generic and already consumed by pipelinq).
  Recommended follow-up, not part of this change: lift the generic matcher core (message iteration,
  cursor, idempotent leaf linking, per-user settings) from pipelinq's `EmailMatchService` into the
  email leaf, leaving each app only its recognizer — pipelinq contributes the correspondent-address
  recognizer, procest the case-number recognizer. Recorded as the design direction in design.md D6.
- **pipelinq** — untouched; its `EmailMatchService` is prior art and the donor for the future shared
  core.

## Out of Scope

- Auto-creating a case from an unmatched email (`mailObjectTemplate`) — explicitly excluded: no match
  means nothing happens.
- Changing or replacing `InboundEmailJob` (shared functional mailbox, direct IMAP, archival path). The
  two paths coexist; convergence criteria in design D5. Fixing that job's pattern/identifier mismatch
  is flagged there as its own defect fix.
- Attachment archival to the case dossier (that is `EmailArchivalService`'s existing surface, driven
  by the shared-mailbox path).
- Lifting the shared matcher core into the email leaf (recommended direction, separate openregister
  change).

## Success Criteria

- An email whose subject or body contains `2026-0042` is linked to that case within 5 minutes for an
  enabled user, visible in the case's Mail leaf surface, and re-runs create no duplicate links.
- An email with several case numbers is linked to each; an email with none causes no write and no
  case creation.
- With the instance toggle off, the user toggle off, or the register unconfigured, the job performs
  zero OpenRegister writes.
