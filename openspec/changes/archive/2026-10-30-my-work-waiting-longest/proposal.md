# Mijn werk: the waiting tile says how long the longest wait is

## Why
DqMijnWerk's "Wacht op inwoner" tile draws "langste: 9 dagen" under its count. The simple Mijn werk said
"{count} langer dan een week", another fact. The case's `currentStatusDwellDays` cannot give the board's
number: it is written only at a status change, so it reads 0 for a case that is still waiting.

## What changes
- **Page.** The tile's caption reads "longest: {count} days" (nl "langste: {count} dagen"), where `{count}` is
  the whole days since the waiting case that entered its status first did so: nextcloud-vue's caption source
  `metric: "daysSinceEarliest"` over `currentStatusEnteredAt`, with the tile's own filter. Without a wait of a
  day or more the caption is left out. This needs nextcloud-vue `feat/dq-c12-stat-caption-days-since-earliest`;
  an older library cannot read the metric and leaves the caption out.

## Out of scope
- Measuring from the supplement request (aanvullingsverzoek) instead of the status: the status is what the
  board's caption counts from.
