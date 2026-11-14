# Design: case-page-and-list-as-a-place

## D-1. Declarations only, because the components are not ours

Every capability in this cluster is a list or a page behaviour, and those
live in nextcloud-vue. dossiq declaring them is one manifest key each.
dossiq building them is a second list component in the fleet.

So this change adds no `.vue` file and no PHP. If a task here starts to
need one, the work is on the wrong side of the boundary.

## D-2. Three pages, not every page

`#Cases` and `#Queue` are worked from, so they get the split view and the
list navigation. `#CaseDetail` gets the preview card. The case types page,
the settings pages and the dashboards get none of it: nobody triages a
case type.

Declaring it everywhere would cost nothing to write and would make every
future page a decision somebody forgets to make.

## D-3. Keeping the position is the capability, not the layout

C-search-30 asks for two things and the second is the one that matters:
"the list and one open case sit side by side, and the list keeps its
scroll position". A split view that re-fetches and re-scrolls on every
case is the same round trip with a narrower column.

So the declaration says the list keeps its position, and the e2e asserts
the position and not the presence of two panes.

## D-4. Next and previous mean the list you came from

Frappe's `get_navigation_tickets` takes the filters and the ordering with
it. Stepping to the next case in an unfiltered list when the handler was
working a filtered one is worse than no stepping at all, because it looks
like it worked.

## D-5. Personal options go where a Nextcloud user already looks

`src/personalSettings.js` exists. C-configuration-55 is passed by four
driven systems and it is one screen per product. Putting dossiq's options
anywhere else adds a second place to look for a setting.

## D-6. A held order is per person and never the default

C-search-19 lets a person impose an order the data does not have. It is
theirs, it does not change anybody else's list, and a list with no held
order sorts as it does today. Nothing about the default view changes on
the day this ships.
