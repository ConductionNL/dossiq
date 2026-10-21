# r21-enforcement-advice-board

## Why

The enforcement advice page (`/settings/lhs-recommendations/:id`) differed from board dossiq/DqHandhaving in three
places that configuration decides:

- the header carried the library's automatic Follow toggle and follower count, which the board does not draw;
- the recommendation and the deviation were two full-width data panels in the main column, while the board puts
  "Aanbeveling" and "Afwijking en definitief" as cards in the side column, under Zaak and Classificatie;
- the history card was titled "Geschiedenis", while the board and every other record page say "Historie".

## What changes

- The page sets `follow: false`.
- The two data panels become the side cards `side-recommendation` and `side-override`, with every field they showed,
  enum values in words, users by display name, and the board's closing line on each card. Deviating stays the
  header's "Afwijken met reden", which edits the record.
- The main column holds the matrix and the violation.
- The history card uses the "Record history" string (nl "Historie").
