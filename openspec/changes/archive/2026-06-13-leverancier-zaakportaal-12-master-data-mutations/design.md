# Design — Member 12: Master Data Self-Service (code)

## Scope

Full master-data slice — mutation service, processing job, profile controller, and Vue profile +
IBAN-verification UI. Reads the `Supplier` schema and the IBAN/accreditatie/mutatie case types
from member 01.

## Declarative-first (ADR-031) note

No new schema or case type. `Supplier` records via OpenRegister ObjectService (ADR-001). The
per-field approval policy (auto vs 4-eyes vs verify) is one unified mutation service rather than
field-specific controllers — the long-term single write path.

## Approach

- `updateAddress` / `updateContactPerson` — apply immediately, write audit, email confirmation.
- `requestIBANChange` — requires re-auth; creates a `Leverancier-IBAN-wijziging` Procest zaak for
  4-eyes; does NOT apply until approved; `ProcessMasterDataMutationsJob` finalises on approval.
- `submitForVerification` — creates `Leverancier-accreditatie-verificatie` zaak with attachments;
  not auto-applied.
- `ProfileController` exposes GET /profile and POST /profile/{address,contact,iban,accreditations}.
- `IBANVerificationFlow` (3 steps) + `ProfileForm` Vue components.

## Security (ADR-005)

- IBAN change gated on the financial re-auth flag (member 02) + 4-eyes approval — fail-closed.
- All mutations audit-logged with old/new values, IBAN masked.
- Address/contact auto-apply only; SBI/accreditation never auto-applied.
