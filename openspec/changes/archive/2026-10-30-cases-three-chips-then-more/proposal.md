# Proposal: cases-three-chips-then-more

## Why

Ruben (23 Oct): the case list's default views are the board's first three on the row (Alles, Mijn zaken, Termijn deze
week), the others behind "Meer standaardweergaven". The boards were amended to that (design-system #429). Live (25 Oct,
REFRESH-3) still drew six chips (All, Mine, Due this week, Waiting on the applicant, Woo requests, Suspended) before the
More chip: the simple profile's `quickFilterMaxVisible` was 6.

## What changes

The simple profile's Cases page shows three chips on the row (`quickFilterMaxVisible: 3`); the other lenses keep their
order and counts behind the More chip. Users keep every lens. Configuration only.
