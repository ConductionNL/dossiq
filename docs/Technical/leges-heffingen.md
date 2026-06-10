# Leges-heffingen (municipal fee calculation)

Automated calculation, invoicing and refunding of municipal fees (leges) on
cases, grounded in Gemeentewet art. 229 and the VNG Modelverordening leges.

## Entity model

All leges data is stored as OpenRegister objects in the Procest register. The
schemas are declared in the modular fragment `lib/Settings/register.d/30-leges.json`
(ADR-037 - the monolith `procest_register.json` is never edited).

```
legesTariefTabel  (verordening version per fiscal year)
  └─ legesTarief        (tariff line, coupled to a zaaktype)
       ├─ legesVariant  (sub-tariff selected on case attributes)
       └─ legesKorting   (discount/exemption, condition-driven)
legesBerekening   (concrete calculation per case, with audit trail)
  └─ legesRestitutie (refund decision)
```

Amounts are stored in **eurocents** (integers) throughout.

## Calculation flow

1. **Import** - an admin imports a verordening from a decidesk raadsbesluit
   (`LegesVerordingImportService`). CSV and XLSX attachments are parsed natively
   (XLSX as a zipped XML set, XXE-safe). A `concept` `legesTariefTabel` plus its
   `legesTarief` rows are created, with a diff vs. the current table.
2. **Approve** - `LegesVerordeningService::approve()` flips `concept → vastgesteld`
   and closes the previous overlapping table (`geldigTotEnMet`).
3. **Calculate** - on case creation (`LegesCaseCreatedListener`) or on demand,
   `LegesCaseCalculationService::calculateForCase()`:
   - resolves the `vastgesteld` table valid on the case reference date (peildatum
     = `startDate`, never a later verordening),
   - selects the `legesTarief` coupled to the case's `caseType`,
   - evaluates `legesVariant` conditions (`LegesConditionEvaluator`),
   - computes the base amount (vast / percentage / staffel / variant override),
   - applies `legesKorting` records (age / income / repeat-application), flagging
     `pending_minima_check` when an income-dependent exemption needs verification,
   - splits VAT and persists a `legesBerekening` with a human-readable
     `berekeningsToelichting`.
4. **Invoice** - `LegesShillinqService::createInvoice()` posts to the shillinq
   accounts-receivable API (gated by `leges_shillinq_enabled` config).
5. **Refund** - on withdrawal (`LegesCaseWithdrawnListener`) or on demand,
   `LegesRestitutieService::createRestitutie()` applies the phase staffel
   (100 % within term / 75 % in progress / 0 % after decision) and requests a
   credit invoice.

## API

| Method | Path | Auth |
|--------|------|------|
| POST | `/api/leges/import-verordening` | admin |
| GET  | `/api/admin/leges/verordeningen` | admin |
| PATCH | `/api/admin/leges/verordeningen/{id}` | admin |
| POST | `/api/admin/leges/verordeningen/{id}/approve` | admin |
| GET  | `/api/cases/{caseId}/leges` | user (case-scoped) |
| POST | `/api/cases/{caseId}/leges/calculate` | user (case-scoped) |
| GET  | `/api/cases/{caseId}/leges/audit-trail` | user (case-scoped) |
| POST | `/api/cases/{caseId}/leges/refund` | user (case-scoped) |

Per-case endpoints are `#[NoAdminRequired]` and verify the caller can access the
referenced case (via `CaseSharingService::canUserAccessCase`) before acting:
IDOR-safe (ADR-005). The BSN is never logged raw and no secret is returned.

## Frontend

- `LegesBerekeningPanel` - case-detail sidebar tab (registry key, manifest-v2).
- `LegesVerordeningenAdmin` - admin page (manifest fragment `src/manifest.d/30-leges.json`).
- Dialogs: `LegesRefundDialog`, `LegesVerordeningImportDialog`.

## Configuration keys

`leges_*_schema` (auto-configured on import via `SettingsService`),
`leges_shillinq_enabled`, `leges_shillinq_source`, `leges_betalingstermijn_dagen`.

## Seed data

`SeedLegesData` repair step seeds an example "Legesverordening 2026 Gemeente
Amsterdam" with paspoort, rijbewijs (+ spoed variant + 65-plus exemption),
omgevingsvergunning (bouwsom staffel) and APV-evenement tariffs.
