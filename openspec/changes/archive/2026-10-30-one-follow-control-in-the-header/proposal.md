---
kind: code
depends_on: []
---

# Proposal: one-follow-control-in-the-header

## Why

On the review instance (10 Oct, finding F1) the case page showed two Follow
controls. nextcloud-vue's CnDetailPage draws its own CnFollowToggle beside the
case title for every object that carries `@self.watching`, with a bell and a
follower count. dossiq's `CaseFollowStrip` drew a second one in the banner row
("Volg deze zaak, 0 volgers"). The two kept their own state, so pressing one
left the other reading the opposite. `one-follow-control` asked for one control,
and the board DqZaak draws one: Volgen, in the header.

## What Changes

- `CaseBannerStack` no longer mounts `CaseFollowStrip`. The header toggle from
  the library is the one Follow control, with its notifications bell.
- `CaseFollowStrip.vue` and its `case-follow` widget type are deleted, and so are
  the `watcherApi` helpers only the strip used (`setNotify`, `notifiesOf`,
  `followerCountOf`).
- The Follow row action on the lists and the Followers section on the People tab
  stay as they are.

## Impact

- `src/components/case/CaseBannerStack.vue`, `src/registry.js`,
  `src/services/watcherApi.js`, the deleted strip.
- Tests: `tests/vitest/caseFollowers.spec.js`, `tests/vitest/caseActionsMenu.spec.js`,
  `tests/e2e/case-followers.spec.ts`, `tests/e2e/case-number-and-favourites.spec.ts`
  now drive the header toggle (`cn-follow-toggle-button`, `cn-follow-toggle-notify`).
- No backend, schema or register change.
