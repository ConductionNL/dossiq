Three lists that a reader could not use on the review instance (10 Oct, findings F3, F6 and F7).

**Assigned to me cards (F6).** The case type chip ran 72px past the card edge; it now ends in an ellipsis inside the card, with the full case type as tooltip. A late deadline was coloured with `--color-error`, which the NL Design System theme maps to the tint #FFE7E7 (1.18:1 on white). It now uses `--color-error-text` (#8A0000, 10.09:1), as DqAanMijToegewezen draws late text in dark red.

**Your team's queue (F3).** The Deadline column showed the stored ISO date. The column now carries `format: date`, on the My work team view, the Dashboard preset and the Cases you follow preset. The Team column on Cases stays off by default: DqZaken draws no Team column (Zaak, Type, Status, Behandelaar, Termijn).

**Recently opened tile (F7).** Three columns asked for about 490px in a 340px tile, so the date was cut off. As DqDashboard draws it, the case number is now the title's second line (`secondary`), the title wraps, and the date ends the row. Fixed layout with column widths was tried first and did not hold: the tile hides its header and the columns split evenly.

OpenSpec: `my-work-cards-and-team-tiles-read-right` (REQ-MW-CARD-01, REQ-MW-CARD-02, REQ-DASH-030), `openspec validate --strict` clean.

**Verified live** on :8098 with this branch built: chip overflow -18px (inside), late deadline contrast 10.09:1; team queue shows "7 december"; recent table 342px in a 344px tile, date cell ends at 1391 inside 1392. Screenshots `dqf-f6-mywork-cards-after.png`, `dqf-f3-team-queue-after.png`, `dqf-f7-recent-tile-after.png`.

**Local verification** (after merging origin/development 421a8c7a0, head f698e9ef6): `flock ~/lanes/heavy.lock npx vitest run --maxWorkers=2` exit 0 (278 files, 3207 tests); `npm run lint` exit 0; `npm run format` exit 0; `npm run check:manifest` exit 0; `npm run test:l10n` exit 0; `npm run check:l10n-js` exit 0; `npm run check:schema-l10n` exit 0; `npm run check:debt-ratchet` exit 0; `openspec validate `my-work-cards-and-team-tiles-read-right` --strict` exit 0. No PHP changed.

Inherited, not touched: about 46 other dossiq rules colour text with `--color-error`, which reads 1.2:1 under the same theme; the theme's mapping is the better place to fix that.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
