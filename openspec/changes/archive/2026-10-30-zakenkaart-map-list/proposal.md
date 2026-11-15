# Proposal: zakenkaart-map-list

## Why

DqZakenKaart draws each row of the map's numbered list as the title, "address · case number" under it, and the case
type and status as pills. Live showed the case number only and no pills: a case carries its type as a UUID and no
address text (the address is a `case-location` record). On a case page the empty "Locaties" line sat 24px below the
card's title against the board's 12, because the line carried its own 12px top padding.

## What changes

- A `caseAddress` formatter reads a case's first `case-location` (its label), once per case per page load.
- The simple profile's map list shows `"{id|caseAddress} · {identifier}"`, falling back to `"{identifier}"`, and pills
  for the case type (`caseTypeName`) and the status (`statusTypeName`, in its status type's `colour` through `resolve`). This needs nextcloud-vue `map-list-formatters`
  (pill `formatter`, formatter tokens, `sub` as a list).
- The empty locations line has no padding of its own and reads 14px.
