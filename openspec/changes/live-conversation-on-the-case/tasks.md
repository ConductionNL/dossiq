# Tasks: live-conversation-on-the-case

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 70, candidates
C-communication-31, C-case-core-6 and C-communication-25;
C-communication-67 is hermiq's under decision D13. No `must` in the
cluster, so every member enters on relevance under D6. Consumes Nextcloud
Talk, Nextcloud Files and hermiq; every half has an artefact.

- [ ] 1.1 Lift `HearingService`'s Talk room creation to a case-level
  conversation service, with the bezwaar hoorzitting as one configured use
  (D-1).
  - `tests/unit/Service/CaseConversationServiceTest.php`
  - `@spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md`
- [ ] 1.2 Keep the hoorzitting behaviour unchanged: scheduling, attendance
  and minutes (D-1).
  - `tests/unit/Service/Bezwaar/HearingServiceTest.php`
- [ ] 1.3 No Talk, no affordance, with the case saying why (D-1).
- [ ] 2.1 Record the conversation on the case: the moment, the
  participants and the duration (D-2).
  - `tests/vitest/caseConversationRecord.spec.js`
- [ ] 2.2 File the recording, transcript or minutes as a case document
  through `documents-live-on-the-case`, under the case type's visibility
  declaration (D-2).
- [ ] 3.1 Attach a voice note or a screen capture to a case or a task,
  landing as a case document; absent where the platform has no recorder
  (D-3).
  - `tests/unit/Service/CaseCaptureTest.php`
- [ ] 4.1 `case.isMajor` as a permissioned act; `caseType`: the responders
  it names (D-4).
  - `tests/unit/Service/MajorCaseDeclarationTest.php`
- [ ] 4.2 Declaring it opens exactly one channel and notifies the
  responders over the notification dialect, recording who and when (D-4).
- [ ] 4.3 Refuse the declaration when the responders cannot be resolved,
  per ADR-102 (D-4).
- [ ] 4.4 Close the channel with the case and file what was said on it
  (D-5).
- [ ] 5.1 `tests/unit/Architecture/DeclaredToolAssumesNoTypingTest.php`:
  no declared tool names a keyboard affordance or a typed format (D-6).
- [ ] 5.2 Confirm dossiq ships no speech recognition, and record
  C-communication-67 as hermiq's under D13.
- [ ] 5.3 Dutch and English strings.
- [ ] 5.4 `tests/e2e/live-conversation-on-the-case.spec.ts`: a
  conversation started from an ordinary case, the hoorzitting unchanged,
  a recording filed as a case document and not shown in the portal, a
  voice note on a toezichtzaak, a major case opening one channel with four
  responders, a second declaration finding the same channel, and the
  channel closing with the case;
  `openspec validate live-conversation-on-the-case --strict`.
