# Proposal: history-cards-compact-rows

## Why

Boards DqSociaalDomein and DqHandhaving draw the side card Historie with rows 10px above and below ("Inzage medisch
verslag" over "Fatma Kaya · doel onderzoek · 6 okt"; "Advies berekend" over "Systeem · 24 okt 13:37"), the first row
10px under the title. The board look pads a stacked history row 12px, as DqTaak draws it, so r34 read DqSociaalDomein
Historie content.gapBelowTitle 21 > 23.7.

## What changes

- The social case side card Historie (`side-social-history`) and the enforcement advice side card Historie
  (`side-history` on LhsRecommendationDetail) set `content.density: "compact"`. Needs nextcloud-vue
  fix/r34-audit-card-compact-stacked-rows (the density on the audit card); without it the key is ignored.
