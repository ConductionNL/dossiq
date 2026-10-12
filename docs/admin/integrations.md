---
id: integrations
title: External integrations (test environments)
sidebar_position: 4
description: How Dossiq's external integrations (BRP, KvK, DSO, DigiD/eHerkenning, e-Depot) are wired to real TEST environments behind a per-integration config tier. Every seam defaults to log — no external call happens unknowingly.
---

# External integrations & test environments

Dossiq's external-integration seams are wired to **real test environments** behind a uniform
per-integration config tier (`external-integrations-test-environments`). The design rule is
fail-closed: **every seam defaults to `log`** (dormant — no external call), so a fresh install or a
dev instance never contacts an external service unknowingly. An operator opts a seam into a live
tier explicitly.

## Config-tier model

One app-config key selects the adapter tier per integration, plus tier-specific credentials:

| Key | Values | Default |
|-----|--------|---------|
| `integration.brp.mode` | `log` \| `mock` \| `test` | `log` |
| `integration.brp.baseUrl` | e.g. `http://localhost:5010/haalcentraal/api/brp` (mock) or `https://proefomgeving.haalcentraal.nl/haalcentraal/api/brp` | unset |
| `integration.brp.apiKey` | proefomgeving X-API-KEY | unset |
| `integration.dso.baseUrl` | `https://service.pre.omgevingswet.overheid.nl` | unset |

Set a tier with, e.g.:

```bash
occ config:app:set dossiq integration.brp.mode --value mock
occ config:app:set dossiq integration.brp.baseUrl --value http://personen-mock:5010/haalcentraal/api/brp
```

An unknown or unset mode always falls back to `log`.

## Per-integration status

### BRP (Haal Centraal Personen bevragen) — beta

- **Test env**: official OSS mock `ghcr.io/brp-api/personen-mock` (runs fully offline, port 5010,
  `/haalcentraal/api/brp/personen`) and the official proefomgeving
  `https://proefomgeving.haalcentraal.nl/haalcentraal/api/brp`.
- **Adapter**: `HaalCentraalBrpAdapter` (X-API-KEY, configurable base URL), selected by
  `integration.brp.mode`. Never logs the BSN (AVG art. 9); fail-soft on transport errors.
- **Contract lane**: offline against the personen-mock koppelvlak (`tests/Unit/Service/External/BrpKvkContractTest.php`
  + recorded fixture in `tests/fixtures/contracts/brp/`). The fixtures are aligned with the
  `brp-kvk-register-sets` seed personas.
- **Access request**: proefomgeving X-API-KEY is granted ad-hoc by e-mail to the BRP-API product
  owner (see the getting-started page of `github.com/BRP-API/Haal-Centraal-BRP-bevragen`).
  **Status: not requested in this session** (no live proefomgeving credential is bundled).

### KvK (Handelsregister)

Dossiq ships no KvK client. Integriq holds the KvK source and keeps a company's register data current. Configure it there.

### DSO / Omgevingswet — beta (config-ready seam)

- **Test env**: pre-productie / oefenomgeving `https://service.pre.omgevingswet.overheid.nl`.
- **Seam**: `integration.dso.baseUrl`, read the same way every other integration reads its
  settings, through `IntegrationMode::setting(integration: 'dso', key: 'baseUrl')`.
- **There is no DSO client yet, and the bearer token key is gone.** `DsoLvAuthService` held
  `dso_lv_auth_token` and built an `Authorization: Bearer` header, and nothing in the app ever
  asked it for one: no file under `lib/` that touches DSO uses `IClientService`, so dossiq makes
  no outbound DSO call at all. DSO today is INBOUND, and it does not reach dossiq directly: integriq
  receives the STAM verzoek and maps it, and `VergunningaanvraagCreatedListener` makes the one
  case. The former public webhook (`/api/vth/dso/intake`, `dso_webhook_secret`) is gone. The token
  key comes back with the client that needs it, named for whatever it actually sends.
- **Intake switch**: the listener reads `dso_vergunningaanvraag_schema`. With integriq installed,
  install and upgrade fill it with the id of integriq's `dso_verzoek` schema when it was never set.
  To turn DSO intake off, set it empty (`occ config:app:set dossiq dso_vergunningaanvraag_schema --value=`);
  an empty value is kept. Deleting the key hands it back to the next upgrade. While it is empty the
  administration overview warns "Dossiq DSO intake".
- **Access request**: the DSO pre-prod endpoint is **certificate-bound** — it needs the DSO
  aansluittraject (service request via the Ontwikkelaarsportaal → client_id + test API key,
  ~5 working days per step) plus a PKIoverheid OIN/HRN certificate. **This is a formal
  aansluittraject and is OUT OF SESSION SCOPE**; the config keys are ready so an operator points
  them at pre-prod once granted, with no code change.

### DigiD / eHerkenning (Logius)

dossiq has no DigiD or eHerkenning adapter of its own. Logins through DigiD,
eHerkenning and eIDAS run through integriq's identity broker
(`OCA\Integriq\Auth\Idp`, routes `/apps/integriq/api/idp/{provider}/start` and
`/callback`). Until an administrator binds a live broker there, integriq's dormant
adapter refuses every login and makes no external call. The former
`integration.digid.mode` key is gone and has no effect.

### e-Depot (Nationaal Archief) — beta (customer-side aansluittraject)

- The e-Depot **submission transport** is OpenRegister's (`Edepot/Transport/*`) after
  `migrate-archival-to-or` retires dossiq's `EDepotSubmissionAdapterInterface`. This change
  contributes only: (i) offline **MDTO XSD validation** of generated SIPs (lands with the OR
  archival pipeline — `NationaalArchief/MDTO-XSD`), and (ii) a manual **Preservica Starter**
  (`https://starter.preservica.com`, free 5 GB tier) sandbox rehearsal lane.
- **Access request**: the NA e-Depot **aansluittraject** (impact analysis + intake) is only open to
  zorgdragers/overheden and takes **months** — it is a **customer-side track dossiq supports but
  cannot initiate**, and is OUT OF SESSION SCOPE. Note: MDTO supersedes the legacy TMLO naming.

## Access-request register

| Integration | Grant | Status (this session) |
|-------------|-------|------------------------|
| BRP proefomgeving X-API-KEY | e-mail to BRP-API product owner | not requested (offline mock lane wired) |
| DSO pre-prod (client_id + test key + PKIoverheid cert) | aansluittraject | blocked — formal aansluittraject, customer-side, out of scope |
| Logius DigiD preproductie + PKIoverheid cert | supplier application | blocked — formal aansluiting, out of scope (simulator shipped) |
| Preservica Starter | instant signup | not exercised (belongs with migrate-archival-to-or) |

## What could not be verified in this session

- No live external calls were made against BRP proefomgeving, DSO pre-prod, Logius preprod, or a
  real e-Depot — those require credentials/certificates from formal aansluittrajecten that are
  customer-side and out of session scope. The adapters + config tiers + offline/recorded contract
  lanes are shipped and green; promotion of BRP/KvK from beta → stable is gated on an end-to-end
  run against the official environment once the credential is granted.
