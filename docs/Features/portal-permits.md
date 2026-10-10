# Permits in the resident portal

A resident sees the parking permits they hold in the portal, on the parking theme page. They can also ask for a new licence plate on a permit. Dossiq keeps the permits; Portaliq shows them.

## What a resident sees

Each permit shows its title, whether it is valid, the licence plate, the address and the first day it is valid. The page counts them: "2 vergunningen". A revoked permit is not shown.

Dossiq gives the portal the collection `mijnVergunningen` on the `permit` schema. It is scoped to the resident's portal subject, so a resident only reads their own permits. Portaliq works out "Geldig" from the two dates.

## Changing the licence plate

"Kenteken wijzigen" asks for the new plate. The portal does not change the permit. Dossiq opens a case of the type "Kenteken wijzigen parkeervergunning" with the permit and the new plate. A handler checks it and decides. The permit keeps its old plate until then, because parking enforcement reads it.

The portal sends the request to `POST /apps/dossiq/api/portal/vergunning/kenteken`. Dossiq checks the signed portal assertion and that the permit belongs to that resident. A permit that is not theirs gets the same answer as one that does not exist.

## Setting it up

A case type issues a permit when it declares `issuesPermit`:

| Key | What it does |
| --- | --- |
| `kind` | The kind of permit, for example `parkeren-bewoner` |
| `theme` | The portal theme the permit appears under, for example `parkeren` |
| `titleTemplate` | The permit title, with case properties in double braces |
| `detailsFromCase` | Which case property fills `kenteken` and `adres` |
| `changeCaseType` | The case type a plate change opens |

Dossiq ships two case types for this: "Parkeervergunning bewoners" and "Kenteken wijzigen parkeervergunning".

Which decision issues a permit is still an open question for the product owner (Q-dossiq-L2-4). Until that is settled, nobody creates permits automatically.
