# Proposal: verdict-row-cites-its-ground

## Why

DqZaakDocumenten draws the ground a partly or not public verdict cites under its pill: "Deels openbaar" over
"grond 5.1.2.e". The case files' Assessment column showed the pill alone, so each such row was 4px lower on the
board and "Weigeringsgronden tot nu toe" sat 11.7px higher on live (r45).

## What changes

- The Assessment column declares nextcloud-vue's column `subline` ("ground {reviewGrounds|values}") over the
  dossier listing's `reviewGrounds`, the codes of the grounds the document's assessment cites.
- The dossier listing carries `reviewGrounds` beside `reviewVerdict` for each document under review. Until it does,
  the column draws the pill alone, as before.
