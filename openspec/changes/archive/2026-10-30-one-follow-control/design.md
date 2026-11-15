# Design: one-follow-control

## D-1: the strip keeps its place and gains a bell

The Follow strip already sat in the banner row after the archived strip. It stays there, now alone, because following is the one per-reader state on the case page. The bell shows only while you follow and is an icon button with `aria-pressed` and a label that says what a click does. The Follow icon moves from a bell to an eye, so the bell means one thing: notifications.

## D-2: the widget id of the dashboard tile does not change

`favourite-cases` is stored in every reader's own dashboard layout (`userLayout`). Renaming it would drop the tile from every saved layout. The title, lens and empty text change; the id stays.

## D-3: no dossiq store for the switch

The switch is read off the object (`@self.watchNotify`) like `@self.watching`, and written through `PUT .../watch`. An absent switch reads as on, which is what every follow did before.

## D-4: assignment through the schema

dossiq declares the role on the property and writes nothing itself. OpenRegister's listener does the follow on create and update, so a reassignment through any route (the case page, bulk reassign, the API) follows the same way.
