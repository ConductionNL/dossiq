# Proposal: case-type-translations-summary-card

## Why

r25b on :8080, DqZaaktypeOverzicht: the Vertalingen summary drew bare beside the Privacy card, without a card or the
title "Vertalingen", and with no Bewerken button.

## What changes

- `CaseTypeTranslationsWidget` gets `chrome: "card"` in the registry: on the Overview it is a titled card; the
  Translations tab, which shows one widget, still draws it bare.
- The summary's "Edit" header link becomes the Edit button (pencil) the other Overview cards carry, opening the
  Translations tab.

## Reach

Registry and manifest.
