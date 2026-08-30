# Design — Member 06: Tender Frontend (code)

## Scope

Vue tender list and detail views consuming the member 05 API. Frontend-only.

## Declarative-first (ADR-031) note

No new schema or backend behaviour. Per ADR-024 the portal routes are declared in the app
manifest; this member adds the tender list/detail components those routes render.

## Approach

- `TenderList` — table with header-click sorting, status/date/search filtering, status badges
  (gray/blue/green/red/orange). Caches the list ~5 minutes.
- `TenderDetail` — conditional sections: award date + letter download when awarded; rejection
  reason + appeal deadline + evaluation-report download when rejected.
- `TenderStatusBadge` — shared badge component.
- Document download buttons handle `Content-Disposition: attachment` responses.

## Security (ADR-005)

UI never trusts client state for scope — all data comes from the scoped member 05 API. Download
links call the gated backend endpoint. NL Design System components + WCAG 2.1 AA (keyboard nav,
contrast, ARIA) per ADR-010.
