# Proposal: cases-open-by-default

## Why

On :8080 (25 Oct, REFRESH-3) /apps/dossiq/cases listed closed cases (Aanleg fietspad Lindelaan, Melding kapot
speeltoestel Oosthavenplein; both `isFinalStatus: true`, read with a GET) under the line "{shown} van {total} lopende
zaken in uw teams". The data is right: the list applied no open filter. The simple profile's Cases page opens on the
"All" lens, which filters drafts and hidden statuses only, and its page filter is `isTemplate: false`.

## What changes

The simple profile's Cases page opens with `defaultFilters: { isFinalStatus: false, assignedGroup: '@myTeam' }`:
removable default filters, read "Status: lopend" and "Team: Woo en informatie" on the Active line as board DqZaken
draws them (BOARD-SYNC: the "Alles" chip applied no team or open filter). `@myTeam` in default filters resolves once
the team is read (nextcloud-vue f861163ecb, on development). A link that carries filters of its own, or a saved view,
starts from those instead (nextcloud-vue `defaultFilters`). Configuration only.
