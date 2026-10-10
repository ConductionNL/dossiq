Two case page fixes from the review instance (10 Oct, findings F1 and F6).

**One Follow control (F1).** The case page drew two Follow controls: nextcloud-vue's toggle beside the title (CnDetailPage, with its bell and follower count) and dossiq's `CaseFollowStrip` in the banner row. They kept their own state, so pressing one left the other reading the opposite. The board DqZaak draws one Volgen button, in the header. The strip is removed from `CaseBannerStack`, and the component, its `case-follow` registry type and the `watcherApi` helpers only it used (`setNotify`, `notifiesOf`, `followerCountOf`) are deleted. The Follow row action on the lists and the Followers section on the People tab stay. Vitest and e2e now drive the header toggle (`cn-follow-toggle-button`, `cn-follow-toggle-notify`).

**Lifecycle dialog title (F6).** Meer, Levensloop opened a small NcDialog (about 390px) whose centred title "Wat je met deze zaak kunt doen" ran under the close button (title box 532 to 920px, close 882 to 916px). It now opens at `size="normal"`.

OpenSpec: `one-follow-control-in-the-header` (REQ-CM-40a), `lifecycle-dialog-title-clears-the-close-button` (REQ-CM-78), both `openspec validate --strict` clean.

**Verified live** on the review instance (:8098), frontend built from this branch: one follow control on the case page (`cn-follow-toggle-button` count 1, `case-follow` count 0); dialog title box 536 to 916px, close button 982 to 1016px, no overlap. Screenshots `dqf-f1-case-page-after.png`, `dqf-f6-lifecycle-dialog-after.png`.

**Local verification** (after merging origin/development 421a8c7a0, head 14c9ab297): `flock ~/lanes/heavy.lock npx vitest run --maxWorkers=2` exit 0 (277 files, 3192 tests); `npm run lint` exit 0; `npm run format` exit 0; `npm run check:manifest` exit 0; `npm run test:l10n` exit 0; `npm run check:l10n-js` exit 0; `npm run check:schema-l10n` exit 0; `npm run check:debt-ratchet` exit 0; `openspec validate --strict` exit 0 for both changes. No PHP changed.

Inherited, not touched: on current development the case page's tab panel renders below the Flow runs card in the simple profile (measured on a plain development build, so not from this branch).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
