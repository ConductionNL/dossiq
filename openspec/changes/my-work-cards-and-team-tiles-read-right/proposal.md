---
kind: code
depends_on: []
---

# Proposal: my-work-cards-and-team-tiles-read-right

## Why

Three things on the review instance (10 Oct, findings F3, F6 and F7) showed what
a reader cannot use:

- **Assigned to me cards.** The case type chip ("Omgevingsvergunning kleine
  bouwactiviteit") ran 72px past the right edge of the card. A late deadline was
  drawn in `--color-error`, which the NL Design System theme maps to the tint
  #FFE7E7: 1.2:1 on white, far below the 4.5:1 WCAG AA asks for text.
- **Your team's queue.** The Deadline column showed the stored ISO date
  (2026-12-07) rather than a date.
- **Recently opened tile.** The table asked for about 490px in a tile of about
  340px, so the date column, the one that says when, was cut off.

## What Changes

- `MyWorkCaseCard`: the chips end in an ellipsis inside the card, with the full
  case type as tooltip. A late deadline uses `--color-error-text` (#8A0000 under
  the same theme, about 10:1), as the board DqAanMijToegewezen draws late text in dark
  red.
- `src/manifest.json`: the Deadline column of Your team's queue (My work, team
  view, and the Dashboard preset) and of the Cases you follow preset carries
  `format: date`.
- `src/manifest.json`: the Recently opened tile draws two columns, as the board
  DqDashboard does: the title with the case number as a muted line under it, and
  the date on the right. The title wraps instead of pushing the table wider.

## Not changed, by the board

- DqZaken draws no Team column on Cases (Zaak, Type, Status, Behandelaar,
  Termijn), so the Team column stays off by default.

## Impact

- `src/views/MyWorkCaseCard.vue`, `src/manifest.json`.
- Tests: `tests/vitest/myWorkCaseCard.spec.js` (new),
  `tests/vitest/landingViews.spec.js`, `tests/vitest/recentTileViewedAt.spec.js`.
- No backend change.
