# Design — Member 08: User Bevoegdheden UI (code)

## Scope

Vue components on the case detail page (ADR-004, ADR-010, ADR-017). Reads the matrix + role
endpoints from members 02/06. No new backend logic.

## Components

- `BevoegdhedenPanel.vue` — side panel or modal opened from case detail ("Toon bevoegdheden").
  Loads applicable Mandaat records for the case's zaaktype filtered by the user's current role(s).
  Table columns: Mandaat # | Omschrijving | Bevoegdheidtype | Plafond | Subdelegatie | Geldend v/t |
  Details. Only rows for mandaten the user's role(s) hold are shown. Data via the matrix endpoint
  (`GET /api/mandate/zaaktype/{id}/matrix`) + role endpoint; never client-derived authority.
- `MandaatMatrixWidget.vue` — on row click, expand detail: full description, wettelijke grondslag
  link, current role holders (people in the role today), waarnemer note (if the user is acting as a
  substitute), MandateringsBesluit source ("CR 2026-001, effective 2026-01-01"). "What can I do?"
  filter shows only decision types in the current zaaktype the user can unilaterally execute.

## ADR compliance

- ADR-004: server data via `loadState` / REST; no DOM data-attributes.
- Modal isolation: panel/modal in its own file under `src/modals/` if NcModal-based.
- NcSelect filter carries `inputLabel`.
- nl + en i18n (ADR-007); NL Design System CSS variables (ADR-010).

## Security (ADR-005)

The view is read-only and informational. The authoritative gate remains the server-side
`CaseDecisionActionListener` (member 05); this view's "can do" filter is a hint that mirrors the
server's verdict, not an enforcement point. Role holders are shown only to the extent the user is
permitted to see (no BSN, no private data).
