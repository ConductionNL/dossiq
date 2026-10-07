# Tasks: edit-lock-on-the-case-page

Tier: V1. Kind: config. Row 2.27. Waits on openregister
`run-scoped-object-locking` landing on `development`.

- [x] 1.1 Edit form: take on open, release on close and on route leave
  (D-1); vitest for both.
  - `@spec openspec/changes/edit-lock-on-the-case-page/specs/case-management/spec.md`

  🔴 BUILT IN nextcloud-vue, NOT HERE, BECAUSE THE EDIT FORM IS NOT
  dossiq's. The case page is a manifest `detail` page, so `Edit`, the
  form it opens and the locked banner beside it are all CnDetailPage's.
  A take and release written in this app would have to reach into a
  component it does not own, and it would have to be written again in
  every other app on the same page type.

  ConductionNL/nextcloud-vue#1202. `openEditForm()` acquires,
  `closeEditForm()` and a successful save release, and leaving the page
  releases through the composable's scope dispose.

  🔑 THE FORM OPENS SYNCHRONOUSLY AND THE ACQUIRE RUNS BESIDE IT.
  Awaiting the round trip made `openEditForm()` async and reddened 10 of
  the 17 `CnDetailPageFormDialogSlot` tests, which call it and render on
  the next tick. A conflict closes the form again and names the holder,
  which is the same outcome one tick later.

  Tests are `tests/components/CnDetailPageEditLock.spec.js` (8) in that
  repo, plus `src/composables/__tests__/useObjectLock.endpoints.spec.js`
  (5).

- [x] 1.2 Header widget: holder and time from `locked`; Edit `visibleIf`
  (D-2).

  The header half already existed: `CnLockedBanner` has rendered the
  holder, the time and an Unlock button for the viewer's own lock for
  months. What it had never done is show a lock this app took, because
  nothing took one.

  The Edit half is `canEditRecord`, not `visibleIf`. D-2 asks for
  `visibleIf: locked empty or locked.user == @me`, and no manifest
  action carries that vocabulary: `action` is typed
  `additionalProperties: false` and `visibleWhen` holds ONE condition
  with no OR. The page's own Edit button is not a manifest action at
  all, so the gate belongs where the button is.

- [x] 1.3 Refused write: show the message, keep input (D-3).

  A refused ACQUIRE closes the form and shows the server's sentence,
  which names the holder, in `cn-detail-page-lock-refusal`. A refusal for
  any other reason leaves the form open with the input in it: the server
  re-checks on the write, so an unreachable lock costs an optimistic edit
  rather than an editor who cannot work at all.

  ⚠️ A REFUSED WRITE CANNOT BE SHOWN YET, BECAUSE THERE IS NOT ONE.
  See the open ask below.

- [x] 2.1 `tests/e2e/case-edit-lock.spec.ts` with two browser contexts;
  `openspec validate edit-lock-on-the-case-page --strict`.

  Four scenarios, and the second session is a different ACCOUNT rather
  than a second tab: two tabs of one account share a uid, so every
  assertion would pass on a lock that does not distinguish holders at
  all, which is the exact failure this feature exists to prevent. The
  fourth is the control: an untouched case carries no lock, without which
  "the lock is gone" passes on a platform that never wrote one.

  dossiq's own half is `tests/vitest/caseEditLock.spec.js`, which pins
  the two strings the lock url is built from and asserts NO behaviour of
  the lock itself. Copying the library's assertions here would be a
  second answer to a question this app does not own, and it would go on
  passing after the library changed.

## Three defects this found, all silent

Read 2026-09-18, all in `@conduction/nextcloud-vue` 3.2.0 and all fixed
in #1202.

1. **Nothing ever acquired.** `openEditForm()` set a flag. The banner
   only showed a lock some other surface had written, so two handlers
   opening one case both got a form.
2. **The release went to a route that does not exist.** `release()` sent
   `DELETE /lock`. OpenRegister declares `objects#lock` and
   `objects#unlock`, both POST, and no DELETE (`appinfo/routes.php` on
   `development`). `release()` reads a 404 as "already released;
   idempotent" and returns without a word, so every release succeeded
   loudly and did nothing.
3. **The lock url carried the object-cache key, not the schema slug.**
   CnDetailPage passes `<register>-<schema>` as the cache key and it went
   straight into the url, producing `/api/objects/dossiq/dossiq-case/…`,
   a schema no register has.

Each of the three, on its own, is invisible from the outside: the page
renders, the promise resolves, and nobody is told the lock is not there.

## Open ask for openregister

`run-scoped-object-locking` is not shipped: its `tasks.md` on
`development` is unchecked from 1.1 down, including 2.1 "Fix
`findAndValidateExistingObject()` to call the predicate and name the
holder". So a held lock does not refuse a save today, and the feature as
it stands is what the second handler is SHOWN rather than what they are
stopped from doing. The e2e says so in as many words rather than
asserting a refusal that would fail for a reason that is not dossiq's.

D-3's "a 423 from the platform is shown with its message" is already
wired on the acquire path and will carry the write refusal unchanged the
day the guard lands, because it reads `message` and nothing else.
