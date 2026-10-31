# Proposal: social-history-people-and-files

## Why

Board DqSociaalDomein's side Historie lists what people did:

- "Inzage medisch verslag · Fatma Kaya · doel onderzoek · 6 okt";
- "Toestemming vastgelegd · Fatma Kaya · 3 okt".

These are entries that actions write (opening a document, recording consent), not seed data. On :8080 the card showed
three "Zaak gewijzigd" rows by System: background jobs rewriting derived fields, newest first, which pushed every
person's entry off the card. A document view had no label, and its audit row did not name the file.

## What changes

`side-social-history`:

- `excludeActors: ["System"]` (nextcloud-vue feat/audit-card-exclude-actors), so System bookkeeping is left out and the
  people's entries fill the card;
- labels `file.downloaded` "Viewed {changed.data.fileName}" (nl "Inzage {changed.data.fileName}"). The file name is on the
  row with openregister feat/file-download-audit-names-the-file, and the nested key is read with the same nextcloud-vue
  branch.

The purpose ("doel onderzoek") stays on the case-opened row ("Zaak ingezien, doel {purpose}"). A file row does not carry
it.

## Scope

Config (`src/menu-layout.simple.json`) and one translation key.
