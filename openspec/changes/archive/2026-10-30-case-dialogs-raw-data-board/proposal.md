# Proposal: case-dialogs-raw-data-board

## Why

DqZaakDialogen draws Ruwe gegevens with its subtitle once, the JSON, the line "Alleen voor beheerders. Lezen kost niets,
maar het openen komt in het verwerkingenlog." and the buttons Annuleren and Kopiëren. The dialog said its subtitle
twice (as subtitle and again as a paragraph), had no line on who it is for, and offered only Sluiten.

## What changes

- The repeated paragraph goes; the subtitle stays.
- Under the JSON: "Only for administrators. Reading changes nothing, but opening it is recorded in the processing
  log." The case schema opts in to OpenRegister's read logging (`x-openregister-processing.logReads`), so the line is
  true.
- Buttons: Cancel, and Copy (primary, ContentCopy icon), which puts the JSON on the clipboard and says so. A refused
  clipboard says the JSON can be selected and copied by hand.
