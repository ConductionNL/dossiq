# Proposal: case-type-blueprint-summary-look

## Why

DqZaaktypeStatussen draws the "Statussen, resultaten en kenmerken" card's facts as a two-column list: the labels as wide
as the longest one, left aligned, 8px 16px apart, values at 500, on the card's own 22px inset. Live drew a fixed 120px
label column with the labels right aligned (the theme's dl rule), values at 600, and an extra 8px 12px padding plus the
theme's 12px list padding. compdiff r26a: firstRowInsetLeft 23 vs 90.8, minInsetLeft 23 vs 35, gapBelowTitle 31 vs 62.6.

## What changes

The widget's styles only: the list is a `max-content 1fr` grid with an 8px 16px gap and no padding, the labels start
aligned, the values 500, and the widget adds no padding inside the card.
