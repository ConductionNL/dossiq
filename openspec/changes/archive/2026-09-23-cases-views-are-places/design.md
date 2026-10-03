# Design: cases-views-are-places

## D-1. Three pages, one key each

`#Cases`, `#Queue` and `#Tasks` already carry `allowSavedViews: true`.
Each gains the places declaration. No other page does: a saved view of
case types or of shares is a filter, not somewhere a handler works from.

## D-2. The seeded views carry their presentation

Overdue is a list, because it is read top to bottom. The desk view is a
board, because it is worked column by column. Declaring it on the view
means the person who opens it gets the right shape without choosing.

## D-3. Nothing arrives in the navigation uninvited

Pinning is the user's action. dossiq seeds no pinned view, so the
navigation on a fresh install is exactly what it is today, and the ADR-097
budget is unchanged until somebody chooses to spend it.
