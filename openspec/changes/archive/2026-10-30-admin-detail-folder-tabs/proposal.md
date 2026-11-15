# admin-detail-folder-tabs

## Why

The case type page (boards dossiq/DqZaaktypeOverzicht, DqZaaktype, DqZaaktypeVelden) and the organisation page (board
dossiq/DqTenant) drew their part tabs as a segmented pill switch on a grey band, with every part as a card inside a
second card. The boards draw the folder tabs the case page uses: the open tab joins the first card, and the next cards
stand below it.

## What changes

- The case type's and the organisation's tabs widgets drop `variant: "segmented"`, so they take the library's default
  folder strip (`line`), which the board look draws as the boards do.
