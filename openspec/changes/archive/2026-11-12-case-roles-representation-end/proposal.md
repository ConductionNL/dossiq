# Proposal: case-roles-representation-end

## Why

Board DqZaakBetrokkenen "Rollen" (design-system #811) ends J. van Wijk's row with the warning pill "Machtiging tot 31 dec": he acts for Aannemersbedrijf Van Wijk B.V. under Awb 2:1 until that day. The link already holds its end (`validUntil`); the case's Roles section did not ask the parties widget to show it.

## What changes

- The Roles section sets `representationUntil` ("Authorisation until {date}", Dutch "Machtiging tot {date}"), so a party that represents another ends its row with that pill. Needs nextcloud-vue with `representationUntil` (fix/bdq1-representation-end-pill); an older library ignores the key.
