# Tasks: ontvangstbevestiging

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 32, candidates
C-intake-23 (matrix hole), C-communication-54, C-communication-62,
C-communication-55 and C-communication-32. Statutory: Awb 4:3a. Decisions
D12 (the transport is Nextcloud Mail's) and D16 (what may be quoted back).
Waits on dossiq `inbound-mail-filters` for the mail gateway, and on
portaliq for the portal notice half.

- [x] 1.1 `caseType`: declare the acknowledgement, the intake channels
  that owe one, the default channel and whether content stays on the
  platform (D-1, D-2, D-6).
  - `tests/unit/Service/CaseTypeAcknowledgementTest.php`
  - `@spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md`
- [x] 1.2 `lib/Listener/AcknowledgementOnCreateListener.php`: a listener
  on the same `ObjectCreatedEvent` on the `case` schema that
  `DeadlineCaseCreatedListener` already watches, queueing the
  acknowledgement (D-1, D-4).
  - `tests/unit/Listener/AcknowledgementOnCreateListenerTest.php`
- [x] 1.3 Refuse to publish a case type whose statutory entry was removed
  without a warning naming Awb 4:3a (D-9).
- [x] 2.1 `lib/Settings/templates/ontvangstbevestiging.json` and
  `TermijnNotificationService::renderTemplate()`: name the kenmerk, what
  was received, the term and its end date, how to follow the case and who
  to contact, and handle a case type with no beslistermijn.
- [x] 2.2 Read the citizen-visibility flag from the same place the portal
  reads it, so nothing is quoted back that the citizen may not see (D-5).
- [x] 3.1 Route through the citizen's recorded channel where there is one,
  and the case type's default otherwise, over openregister's notification
  dialect (ADR-031) (D-7).
- [x] 3.2 Content-on-the-platform delivery: the message says one is
  waiting and carries no case content (D-6).
- [x] 4.1 Write the outbound communication record on the case: moment,
  channel, recipient, template version (D-8).
  - `tests/vitest/caseCommunicationRecord.spec.js`
- [x] 4.2 An unmet acknowledgement shows on the case, is retried, and can
  be recorded as met another way, with who said so (D-3).
  - `tests/unit/Service/AcknowledgementDutyTest.php`
- [x] 5.1 The declared list of other automatic moments, with the request
  message distinct from the status-change message (D-9).
- [x] 5.2 Dutch and English strings; hand portaliq the administered portal
  notice half of C-communication-32, with the candidate id.
  - handed over as ConductionNL/portaliq#550
- [x] 5.3 `tests/e2e/ontvangstbevestiging.spec.ts`: a portal aanvraag, a
  mail aanvraag, a balie case that owes nothing, a case type with no term,
  a platform-only delivery, a failed send found on the case;
  `openspec validate ontvangstbevestiging --strict`.
