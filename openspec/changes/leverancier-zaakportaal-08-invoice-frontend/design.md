# Design — Member 08: Invoice Frontend (code)

## Scope

Vue invoice list, detail, and age-analysis UI consuming the member 07 API. Frontend-only.

## Declarative-first (ADR-031) note

No new schema or backend behaviour. Routes declared in the app manifest (ADR-024); this member
adds the components those routes render.

## Approach

- `InvoiceList` — sortable/filterable table with status badges (received/under_review/approved/
  disputed/rejected/paid).
- `InvoiceDetail` — expected payment date in a green box when approved; actual payment date +
  delta when paid; TBD message when under review; dispute reason + "Reactie geven" when disputed.
- `AgeAnalysisBar` — stacked horizontal bar (yellow→orange→red→dark red); clicking a bucket
  filters the list to that age range.

## Security (ADR-005)

All data from the scoped member 07 API; the UI does not compute or trust forecasts client-side.
Invoice viewing inherits the financial re-auth gate enforced backend-side. NL Design System +
WCAG 2.1 AA (ADR-010).
