# Tasks: recent-tile-shows-when-you-opened

Kind: config. Size S. Product owner decision on the DqDashboard board,
2026-10-09.

- [x] 1.1 `src/manifest.json`: a `@self.viewedAt` column on the
  `recent-cases` tile with the built-in `daysSince` formatter, muted and
  end-aligned. The tile still declares no `order` of its own.
  - `tests/vitest/caseFavourite.spec.js`
  - `@spec openspec/changes/recent-tile-shows-when-you-opened/specs/case-management/spec.md`
- [x] 1.2 `lensReasonTexts` on the tile for `recent.audit-trail-disabled`,
  `recent.anonymous` and `recent.read-history-unavailable`, English source
  keys with Dutch in `l10n/`. `tests/l10n/check-l10n.js` now extracts the
  values of a `lensReasonTexts` map, so a reason without a translation fails
  `test:l10n`. Needs nextcloud-vue#1421.
- [x] 1.3 `openspec/parity/capabilities.json`: row 2.19 `built.spec` points
  at `openspec/specs/case-management`.
- [ ] 2.1 Live check once the OpenRegister read-history change is merged:
  open a case, return to the Dashboard, and read "Today" on its row.
