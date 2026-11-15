## Why

With the Zuiddrecht example resident installed (portaliq #1227) the overview was seen for the
first time with data. It opened on a plain "Welkom, Sanne" heading, and the question, the running
cases and the newest messages followed each other with no heading between them. The design
(MijnOverzicht) greets by the time of day and names each list: "Wat u nog moet doen", "Lopende
zaken", "Nieuwe berichten". Portaliq already has a `greeting` block and a `label` on every list
block, so the overview can read as designed by declaration alone.

## What Changes

- `PortalPages::residentOverviewBlocks()` opens the overview with `{type: greeting, showDate:
  false}` and gives the `tasks`, `cases` and `inbox` blocks the design's labels. The order of
  the blocks, the case page and every other page stay as they are.

## Out of scope

The case page's order (the Zuiddrecht design reads facts before documents; the dossiq design D4
reads documents first, and that spec stands until Ruben decides), the doubled "Wat er is
gebeurd" and "Documenten" on the case page (portaliq's `citizenCase` screen repeats what the
`timeline` and `documents` blocks show), the "Wacht op u" tag on a case card and the menu groups
of the design.
