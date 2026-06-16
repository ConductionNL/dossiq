# Design — Member 10: Contract Frontend (code)

## Scope

Vue contract list and detail UI consuming the member 09 API, including the renewal-request modal.
Frontend-only.

## Declarative-first (ADR-031) note

No new schema or backend behaviour. Routes declared in the app manifest (ADR-024); this member
adds the rendering components.

## Approach

- `ContractList` — default sort by nearest expiry; orange "Vervalt over [n] dagen" badge +
  highlighted row when `renewalWarning` is true.
- `ContractDetail` — all fields plus the renewal-option block; "Verlenging aanvragen" button shown
  only when renewal option is manual_request AND within 90 days.
- `RenewalRequestModal` — confirmation, posts to the member 09 request endpoint, then disables the
  button and shows "Verlenging aangevraagd op [date]".

## Security (ADR-005)

All data from the scoped member 09 API; renewal posts to the gated backend endpoint. Modal lives
in its own file under `src/modals/` per the modal-isolation gate. NL Design System + WCAG 2.1 AA
(ADR-010).
