# Tasks: unread-state-on-the-case

Tier: V1. Kind: config. Size M. Round 4 discovery cluster 62, candidates
C-search-1, C-case-core-26, C-communication-15, C-communication-6,
C-communication-18 and C-communication-61. No decision. Waits on
openregister's per-user read state, to be specified in openregister, wave
1, proposed there as `object-read-state`, and on nextcloud-vue for the
list column.

- [ ] 1.1 `src/manifest.json`: the unread column on `#Cases` and
  `#Queue`, rendered from openregister's state and stored nowhere in
  dossiq (D-1).
  - `tests/vitest/caseListUnread.spec.js`
  - `@spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md`
- [ ] 1.2 Mark as read and mark as unread, on a case and on a single
  message (D-5).
- [ ] 1.3 A test that fails when dossiq gains a read marker of its own
  (D-1).
- [ ] 2.1 Per-tab unread badges on the case, cleared by opening the tab
  (D-3).
  - `tests/vitest/caseTabBadges.spec.js`
- [ ] 2.2 Clear a notification when its subject is opened (D-4).
- [ ] 3.1 `caseType`: declare which changes make a case unread, defaulting
  to status, documents and messages (D-2).
  - `tests/unit/Service/UnreadTriggersTest.php`
- [ ] 4.1 Dutch and English strings.
- [ ] 4.2 Record the openregister slug here once that lane opens it, and
  ask it whether its change carries the snooze and the notification-list
  filter, C-communication-18 and C-communication-61, or whether those are
  the Nextcloud notification surface.
- [ ] 4.3 `tests/e2e/unread-state-on-the-case.spec.ts`: see what changed
  overnight, mark a case unread, read a tab badge, clear a notification by
  opening its case, and change a non-declared field in bulk without
  lighting up the list;
  `openspec validate unread-state-on-the-case --strict`.
