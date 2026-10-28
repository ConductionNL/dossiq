# Proposal: case-list-tie-order-and-texts

## Why

r34 follow-ups on the case lists (DqZakenSorteren, DqTeamwachtrij, DqZakenKaart):

- Two cases with the same deadline had no second sort key, so their order was the database's. design-system #741
  draws them in :8080's order: 2026-4865 before 2026-4866 (2 nov) and 2026-4845 before 2026-4838 (18 nov). The
  identifier gives the first pair ascending and the second descending, so neither direction keeps both. receivedAt
  ascending keeps both: the case that came in first is first.
- The team queue's deadline read warning for anything within seven days. The board reads "nog 4 dagen" late and
  "nog 6 dagen" soon.
- The map's missing-address card read "8 zonder adres"; the board says "8 zaken zonder adres".
- The cards view's footer read "klik een kolomkop om te sorteren", which the cards view has no column heads for. The
  board says "gesorteerd op termijn, oplopend · sorteren via het zijpaneel".

## What changes

- Every deadline order the case list declares sorts ties by receivedAt ascending: the simple structure's
  `defaultOrder`, the sort option Deadline, and the Woo case type's list order (`x-index.defaultSort`, register data,
  applied on the next register import).
- Team queue deadline rules: error under 5 days left, warning up to 7.
- `mapConfig.list.missingTitle` "{total} cases without an address" (nextcloud-vue map-missing-title-names-the-rows).
- `footerNoteByView.cards` on the Cases page (nextcloud-vue footer-note-per-view).

The texts are page configuration; no procedure is named in code.
