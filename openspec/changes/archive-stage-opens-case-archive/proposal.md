# The closed stage's Archive button opens the case's archive dialog

## Why

On a closed case the what-now card's "Archive it" button opens its own copy of the archive dialog, kept inline in `primaryActionByStage.closed` of the simple case page so the button cannot hide. The copy fell behind the `case-archive` action of the Lifecycle menu: the eyebrow lacked the case number, the facts lacked the archive status afterwards, the reason hint did not say how to restore, and the "let the requester know" checkbox was missing (COMPDIFF DqArchiveren, 23 Oct: "Afronden" without "· 2026-0082", two facts, no checkbox).

## What changes

- The inline stage dialog carries the `case-archive` dialog config, and a test holds the two equal so they cannot drift again.
- The stage entry stays inline with its own `visibleWhen` (not archived), so the button keeps the rule that a stage button cannot hide.

## Impact

`src/menu-layout.simple.json` only. No schema, route or library change.
