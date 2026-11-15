# Proposal: case-history-board-look

## Why

COMPDIFF r19 on DqZaakHistorie flags the History tab's own header and kind chips. The title is 16px with a 24px line
where the board draws 17px at 1.2; the chosen chip "Alles" is fully rounded, outlined and 700 where the board draws an
18px-rounded filled chip at 600 with no outline; its count is a grey bubble where the board draws a light-on-ink one.

## What changes

`CaseTimelineTab` styles its header and chips as DqZaakHistorie: a 17px title over a 14px line 4px apart, 16px above
the chips; chips of 36px, 18px rounded, 14px at 600; the chosen chip filled with no outline and its count light on ink;
counts 10px rounded. The colours stay Nextcloud variables, so dark mode and a theme follow.

## Scope

Styling of one dossiq component. No markup changes beyond a class on the heading block.
