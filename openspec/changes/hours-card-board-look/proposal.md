# Proposal: hours-card-board-look

## Why

Board DqZaak/DqZaakMeer "Uren registreren" ends its week line "op deze zaak",
keeps only the title, the week and the Actions menu in the header, has "Timer
starten" under the form and a note that the hours come through humaniq.

## What changes

The case page passes humaniq's hours leaf `objectNoun: "this case"`,
`compactHeader: true` and `sourceNote: true` (humaniq `feat/hours-board-look`;
an older humaniq ignores them). Nothing of the timer or the Actions menu is
removed.
