# Tasks: one-personal-queue

Tier: V1. Kind: code. Size M. Round 4 discovery cluster 64, candidates
C-tasks-and-phases-26, C-deadlines-2, C-tasks-and-phases-27,
C-tasks-and-phases-5 and C-tasks-and-phases-22; C-tasks-and-phases-37 is
recorded and not built. No `must` in the cluster, so every member enters
on relevance under D6. Reads the task engine, humaniq's hours and leave,
and the platform's notification preferences; every consumed half has an
artefact.

- [ ] 1.1 The queue source contract: a name, a per-person read, what an
  item points at, and what closes it (D-1).
  - `tests/unit/Service/QueueSourceContractTest.php`
  - `@spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md`
- [ ] 1.2 `tests/unit/Architecture/AsksAPersonDeclaresASourceTest.php`:
  every mechanism that asks a person for something declares a source or
  carries a reason-bearing allowlist entry (D-1).
- [ ] 1.3 A source that cannot be read is named as unavailable on the
  queue, per ADR-102 (D-1).
- [ ] 2.1 Declare the sources: assigned cases, coordinator seats, engine
  tasks, consultations, approvals, mentions, and covered work (D-1).
  - `tests/vitest/personalQueueSources.spec.js`
- [ ] 2.2 An item closes with its subject; a person may order, group and
  hide for today, and may not dismiss live work (D-2).
  - `tests/unit/Service/QueueItemLifecycleTest.php`
- [ ] 3.1 The daily digest over the notification dialect, at a chosen
  time, silent on an empty queue, distinct from the assignment notice
  (D-3).
  - `tests/unit/BackgroundJob/DailyDigestJobTest.php`
- [ ] 3.2 Switching it off through the platform's notification
  preferences, not a dossiq setting (D-3).
- [ ] 4.1 The end-of-day screen: everything touched today, an update per
  item (D-4).
  - `tests/vitest/endOfDayScreen.spec.js`
- [ ] 4.2 Place humaniq's hours leaf per item; show no time field when
  humaniq is absent, and store no hours in dossiq (D-4).
- [ ] 5.1 The personal agenda item as a calendar event from a template,
  reaching the queue and entering no case count or report (D-5).
  - `tests/unit/Service/PersonalAgendaItemTest.php`
- [ ] 6.1 The personal stage: private to its owner, no effect on the case
  status, absent from every report (D-6).
  - `tests/unit/Service/PersonalStageTest.php`
  - `tests/vitest/personalStagePrivacy.spec.js`
- [ ] 6.2 Dutch and English strings.
- [ ] 6.3 `tests/e2e/one-personal-queue.spec.ts`: one page holding six
  kinds of waiting work, an item closing with its task, live work that
  cannot be dismissed, a digest sent and one not sent, the end-of-day
  screen with an update recorded, a planned item that is not a case, and a
  personal stage the other handler cannot see;
  `openspec validate one-personal-queue --strict`.
