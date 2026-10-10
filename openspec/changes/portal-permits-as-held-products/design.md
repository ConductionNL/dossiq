# Design: portal-permits-as-held-products

## Screen

Board **ThemaOverzicht** on canvas `5NkFW28vZUUij43xzxHg5a` (portaliq renders it; dossiq supplies the data).

| Board element | Dossiq supplies |
| --- | --- |
| "Mijn parkeervergunningen" | the portal's `productsLabel` for theme `parkeren` (portaliq) |
| "2 vergunningen" | `countLabel: { one: "vergunning", other: "vergunningen" }` |
| "Bewonersvergunning binnenstad" | `titleField: "title"` |
| Tag "Geldig" | computed by portaliq from `validFromField: "validFrom"`, `validUntilField: "validUntil"`; a `revoked` permit is not projected |
| "Kenteken GZ-482-K · Lindelaan 12 · ingegaan op 1 januari 2026" | `metaFields: ["kenteken", "adres"]` with `fieldConfigs` labels, plus `validFrom` |
| "Geldig tot en met 31 december 2026" | `validUntil` |
| Card "Kenteken wijzigen", "Rijdt u in een andere auto? Zet het nieuwe kenteken op uw vergunning." | the `update` action `changePermitPlate`, `theme: parkeren`, `when: { field: "kind", op: "eq", value: "parkeren-bewoner" }` |

## D1. The `permit` schema

| Property | Type | Notes |
| --- | --- | --- |
| `title` | string | From `titleTemplate`, for example "Bewonersvergunning {{ case.zone }}". |
| `kind` | string | `parkeren-bewoner`, `parkeren-bezoeker`, `parkeren-bedrijf`. |
| `theme` | string | `parkeren`. |
| `portalSubject` | string | Copied from the case; the scope field. |
| `case`, `decision` | uuid | Where it came from. |
| `validFrom`, `validUntil` | date | From the decision's `effectiveDate` and `expiryDate`. |
| `status` | enum `active`, `suspended`, `revoked` | |
| `kenteken`, `adres` | string | Top-level, not under `details`: portaliq's products keys take plain field names only (ThemeTagKeys drops `details.kenteken`). The plate is stored without dashes. Amended 10 Oct. |

RBAC: staff of the permit's case type read and write; the portal reads through the contribution's scoped read only.

## D2. Issuing

`PermitFromDecisionListener` listens for a decision saved with a granted result on a case whose case type has `issuesPermit`. It creates one permit per decision (idempotent on `decision`). A later decision on the same case with result `ingetrokken` sets `revoked`. A decision that changes the plate (from the change case, D3) updates `details.kenteken` on the permit the change case names.

## D3. Changing the plate

`changePermitPlate` is an endpoint row action on `mijnVergunningen` (amended 10 Oct: a portaliq `type: update` writes the object it names, which would change the permit) posting `permitId` and `nieuwKenteken` to `POST /api/portal/vergunning/kenteken`. Dossiq does not let the portal write the permit. The action's write path creates a case of the case type `issuesPermit.changeCaseType` names, with the permit as subject object and the new plate as its answer, and the permit itself is changed by the decision on that case. This keeps a handler between the resident and a document the parking enforcement reads.
