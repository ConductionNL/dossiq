# Proposal: rules-tab-dutch-words

## Why

r29 on :8080 (DqZaaktypeRegels): the Rules tab showed calculated fields without a schema title in English words of their
key ("Days overdue", "Days since activity", "Days until deadline") and lines in English: the engine's kind description
("Derives a value from the object and writes it before the object is stored.", "Runs a flow after the object is
stored.") and the case schema's first sentences ("Processing deadline.", "The identifier of the case type, ..."). The
tab translates titles and lines through dossiq's catalogue; those keys had no Dutch.

## What changes

Dutch catalogue entries (en + nl) for every title and line the tab shows for the case schema's 19 calculations and its
flow, e.g. "Dagen te laat", "Berekend uit de zaak, bij elk opslaan.".
