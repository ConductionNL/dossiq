# Tasks: unread-state-on-the-case

Tier: V1. Kind: config. Size M. Round 4 discovery cluster 62, candidates
C-search-1, C-case-core-26, C-communication-15, C-communication-6,
C-communication-18 and C-communication-61. No decision.

OpenRegister's half landed as **`object-read-state`**, merged 2026-09-14
as openregister#3734 (`0eed192ca`): the read-state row, a substantive-change
evaluator reading `x-openregister-read-state`, `_unread` as a lens resolved
inside the query, a transient `unread` flag plus an `unreadCounts` map on
the render path, and the bell's four verbs. Backend only; the list-column
affordance is nextcloud-vue's and is not in yet.

- [x] 1.1 `src/manifest.json`: the unread column on `#Cases` and
  `#Queue`, rendered from OpenRegister's state and stored nowhere in
  dossiq (D-1), plus the Unread lens on `#Cases`.
  - `tests/vitest/caseListUnread.spec.js`
  - `@spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md`
- [x] 1.2 Mark as read and mark as unread, on a case row and on the case
  page (D-5).
- [ ] 1.2b Mark a single MESSAGE read or unread. Not built: a dossiq
  message is a `contactmoment` object with its own uuid, so the gesture is
  the same endpoint on a different schema, but no dossiq surface lists
  messages with a per-row menu yet. It belongs with the communication
  panel's own row actions rather than bolted onto this change.
- [x] 1.3 A test that fails when dossiq gains a read marker of its own
  (D-1): `tests/Unit/Architecture/NoLocalReadStateTest.php`, scanning the
  whole of `lib/` and carrying a control so its silence means something.
- [x] 2.1 Per-panel unread counts on the case, cleared by reading the
  panel (D-3, D-7). On a strip above the tab bar, not on the tab itself.
  - `tests/vitest/caseTabBadges.spec.js`
- [ ] 2.1b The count ON the tab. Waits on `@conduction/nextcloud-vue`:
  `CnTabsWidget` takes no badge per tab and emits no tab change, so an app
  can neither decorate a tab nor learn that one was opened. Clusters 58
  and 15 own that. The strip goes the day it lands.
- [x] 2.2 Clear a notification when its subject is opened (D-4). One
  write: the read-state PUT clears the notices about the object, and a
  PUT carrying `subResource` clears only that panel's.
- [x] 3.1 `caseType.unreadTriggers`: declare which changes make a case
  unread, defaulting to status, documents and messages (D-2).
  - `tests/Unit/Service/UnreadTriggerServiceTest.php`
- [ ] 3.1b Enforce the declaration PER CASE TYPE. Not possible today and
  not faked: OpenRegister resolves `x-openregister-read-state` per schema
  (`SubstantiveChangeEvaluator::annotation()` takes a `Schema`), so the
  enforced floor is the whole vocabulary on the `case` schema. See D-6.
- [x] 4.1 Dutch and English strings.
- [x] 4.2 Record the openregister slug here once that lane opens it, and
  ask it whether its change carries the snooze and the notification-list
  filter.
  - **Slug: `object-read-state`**, openregister#3734.
  - **It carries both.** `snoozedUntil` and `archivedAt` on the
    notification history, with `PUT /api/notification-history/{id}/snooze`
    and `/archive`, plus `subjectType` as the list axis and
    `PUT /api/notification-history/thread/read`. So
    C-communication-18 and C-communication-61 are answered in
    openregister's envelope, and the affordance that drives them is the
    Nextcloud notification surface rather than a dossiq page. dossiq
    renders none of those four verbs and should not: a second bell beside
    Nextcloud's own is how a notification count starts disagreeing with
    itself.
  - **Two asks back to that lane**, both written up in design.md:
    resolve the read-state annotation PER OBJECT so a leaf app can narrow
    it per case type (D-6), and a badge plus a tab-change event on
    `CnTabsWidget` in nextcloud-vue (D-7).
- [x] 4.3 `tests/e2e/unread-state-on-the-case.spec.ts`: see what changed,
  mark a case unread from a row, read the strip, clear a notice by opening
  a case, and change an undeclared field without lighting up the list.
  Two scenarios carry an `@e2e exclude` naming openregister's own tests,
  because both need two users and a browser signs in as one.
