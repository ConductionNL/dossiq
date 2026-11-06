# Proposal: case-type-views-under-more

## Why

DqFilterBeheren on :8080 (24 Oct, r25b): "Klachten" and "Woo-verzoek" stood among the board's three default views, and
read "Zaaktype: <uuid>" until the filter resolved. They come from /api/manifest (CaseTypeViews): the Complaints view
lib/Settings/case-type-views.json declares, and one view per case type the user pinned. Ruben, 23 Oct: the board's three
default views first, the rest under "Meer standaardweergaven".

## What changes

Both kinds of case-type view are `secondary` and carry a description: the declared view its own (Complaints: "Case
type complaint handling"), a pinned case type its title.
