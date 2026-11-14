# Proposal: status-type-board-colours

## Why

The boards draw each status in a colour: received blue, in progress purple, waiting orange, a decision teal, done and
published green. The statuses dossiq shipped held no colour, the schema's grey, or an older colour (in progress
orange, a decision purple), so the status pills on the case lists and the boards read grey or the wrong tone (COMPDIFF
r19: "In behandeling" purple on 31 board pills, grey or orange live).

## What changes

- Every status dossiq ships (register seeds, demo data, the packages, the case type templates and the flow seed) names
  its board colour, from one palette by status name (`StatusTypePalette`).
- An upgrade recolours the statuses dossiq seeded (owner `__system__`) that still hold no colour, grey, or the colour
  dossiq shipped them with (`RecolourShippedStatusTypes`, once per version). A colour an administrator chose stays.
- The six packages whose statuses changed move a minor version, so their installer refreshes the rows nobody edited
  and keeps the edited ones. statusType moves to 1.7.4 and the register to 0.20.60.

## Palette

| Colour | Statuses | Board evidence |
|---|---|---|
| blue | Ontvangen, Ontvangst, Received, Melding ontvangen, Beroep ontvangen, Voorbereiding | Ontvangen 7, Ontvangst 4 |
| purple | In behandeling, In progress, In uitvoering, Uitvoering, Beoordeling, Beoordelen documenten, Beoordeling ontvankelijkheid, Ontvankelijkheidstoets, Onderzoek, Investigation, Assessment, Inhoudelijke toets, Veiligheidstoets, Zoeken documenten, Identiteit vastgesteld, Indicatie, Overleg, Inspectie (and fase 1 to 3), Constatering, Hercontrole, Rapport, Opvolging, Voorstel opstellen, Ambtelijk advies, Parafering, Advies, Verweerschrift in voorbereiding, Hoorzitting afgerond, Zitting afgerond, Inschrijving | In behandeling 31, Beoordelen documenten 2, Beoordeling 1, Onderzoek 1 |
| orange | Wacht op aanvulling, Lakken / Anonimiseren, Hoorzitting gepland, Zitting gepland, Gepland, Gereed voor agendering, Geagendeerd, Bij commissie, Ter goedkeuring, Zienswijze, Vooraankondiging, Begunstigingstermijn, Verweerschrift ingediend, Vergadering | Wacht op inwoner 3, Lakken 2 |
| teal | Besluit, Besluitvorming, Decision, Besluit genomen, Beslissing op bezwaar, Advies uitgebracht, Handhavingsbesluit, Gunning, Uitspraak ontvangen | Besluit 8 |
| green | Afgehandeld, Afgerond, Completed, Publicatie, Bekendmaking, Gegund, Beschikt, Betaald, Akkoord, Schikking | Afgehandeld 7, Gepubliceerd 12, Afgerond 1 |
| red | Niet-ontvankelijk, Afgewezen, Gestrand, Verbeuring, Invordering | none on the boards; the refused, stranded and overdue pattern |
| grey | Ingetrokken, Gearchiveerd | none on the boards; withdrawn and archived |

Statuses the boards do not draw follow the boards' pattern by what they mean. In progress is purple, not blue: the
boards draw it purple on 31 pills and blue on none.

Board conflicts, decided by majority: Gepubliceerd green on 12 pills against teal on 1 (DqArchiveren); Advies ontvangen
teal and green once each on DqBezwaar (Advies uitgebracht takes teal, as a decision step); Afgehandeld, Wacht op inwoner
and Gepubliceerd drawn white on a filter chip on 6 boards, which is a chip and not a status tone.

## Scope

Status rows an administrator created, and any colour one chose, are left alone. The status badge's tints come from the
NL Design System colour tokens; where they differ from the boards' tints that is a theme token, not data.
