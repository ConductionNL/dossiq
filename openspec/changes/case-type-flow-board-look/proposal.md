# Proposal: case-type-flow-board-look

## Why

Board DqZaaktypeFlow draws a case type's flow as one card: an Elementen panel beside a light grid, statuses as white
200px cards with a coloured dot and their steps, lines running between the title rows. Under the title it names the
case type and the version ("Omgevingsvergunning regulier · versie 5 concept"). Live, the lines were drawn up and to the
left of the nodes (the line layer was scaled from its 2000x1500 view box into the canvas), the grid was dark, the palette
was 200px with 11px grey help, and each node had a grey header with a step count.

## What changes

- The line layer is drawn one to one with the nodes (2000x1500 pixels, its view box), and lines join nodes at the middle
  of their title row.
- Nodes: white card, 1px border, 8px radius, title row with the status colour (`statusType.colour`) as a dot, steps at
  13px, "+ Stap toevoegen" in the primary colour; the count badge is gone (the steps are listed under it); ports show on
  hover, focus or selection. The actions menu and the Final badge stay.
- The editor is one 12px-radius card at least 560px high; the Elementen panel is 268px with 14px help in the board's
  words, the keyboard hint kept; the grid is light.
- The page has a subtitle with the case type and the version on screen; Export and Import are secondary buttons; the
  version picker is a 34px field.

## Reach

dossiq's case type flow page only. No nc-vue dependency.
