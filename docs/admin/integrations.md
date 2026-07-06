---
id: integrations
title: External integrations (test environments)
sidebar_position: 4
description: How Procest's external integrations (BRP, KvK, DSO, DigiD/eHerkenning, e-Depot) are wired to real TEST environments behind a per-integration config tier. Every seam defaults to log — no external call happens unknowingly.
---

# External integrations & test environments

Procest's external-integration seams are wired to **real test environments** behind a uniform
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
| `integration.kvk.mode` | `log` \| `test` \| `live` | `log` |
| `integration.kvk.baseUrl` | default `https://api.kvk.nl/test/api` | unset (uses default) |
| `integration.kvk.apiKey` | default = public test key (below) | unset (uses default) |
| `integration.dso.baseUrl` | `https://service.pre.omgevingswet.overheid.nl` | unset |
| `dso_lv_auth_token` (existing) | DSO-LV bearer token | unset (warn + empty headers) |
| `integration.digid.mode` | `log` \| `simulator` | `log` |

Set a tier with, e.g.:

```bash
occ config:app:set procest integration.kvk.mode --value test
occ config:app:set procest integration.brp.mode --value mock
occ config:app:set procest integration.brp.baseUrl --value http://personen-mock:5010/haalcentraal/api/brp
occ config:app:set procest integration.digid.mode --value simulator
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

### KvK (Handelsregister Zoeken) — beta

- **Test env**: KvK Developer Portal test environment `https://api.kvk.nl/test/api/v2/zoeken`.
- **Public test key**: `l7xx1f2691f2520d487b902f4e0b57a0b197` — published openly on
  developers.kvk.nl/documentation/testing. It is NOT a secret; it only unlocks the fixed set of
  fictitious companies (KVK 69599084, 68750110, 69599068, 55344526, …). It ships as the adapter
  default (`KvkApiAdapter::PUBLIC_TEST_API_KEY`).
- **Adapter**: `KvkApiAdapter`, selected by `integration.kvk.mode=test`. Fail-soft on transport
  errors.
- **Contract lane**: `tests/fixtures/contracts/kvk/zoeken-69599084.json` (verbatim test-API
  response, fetched 2026-07-06); the network lane against the live test API is nightly/label-gated.

### DSO / Omgevingswet — beta (config-ready seam)

- **Test env**: pre-productie / oefenomgeving `https://service.pre.omgevingswet.overheid.nl`.
- **Seam**: `DsoLvAuthService` reads `dso_lv_auth_token` (bearer) and `integration.dso.baseUrl`;
  when unset it warns and returns empty headers (fail-open, no external call).
- **Access request**: the DSO pre-prod endpoint is **certificate-bound** — it needs the DSO
  aansluittraject (service request via the Ontwikkelaarsportaal → client_id + test API key,
  ~5 working days per step) plus a PKIoverheid OIN/HRN certificate. **This is a formal
  aansluittraject and is OUT OF SESSION SCOPE**; the config keys are ready so an operator points
  them at pre-prod once granted, with no code change.

### DigiD / eHerkenning (Logius) — beta (simulator only)

- **Simulator**: `SimulatorDigidSamlAdapter` / `SimulatorEHerkenningSamlAdapter` model the
  maykinmedia `django-digid-eherkenning` mock-login pattern — a local BSN/KvK entry, **no real
  SAML**. Selected by `integration.digid.mode=simulator`. The returned assertion is explicitly
  flagged `simulator: true` / `authenticatedBy: simulator` so any consuming surface can render a
  "simulatie" label and mark the session simulator-authenticated.
- **Cap**: permanently **beta**. A simulator proves the login journey and session wiring, NOT the
  SAML koppelvlak. Real signature/artifact validation is only provable against **Logius
  preproductie**, which needs a supplier (Leverancier-route) application + a PKIoverheid
  certificate (weeks of lead time). **This aansluiting is OUT OF SESSION SCOPE**; the real
  SAML-artifact adapter and its preprod lane are a follow-up once the cert is granted.
- **No procest DigiD login page** exists today — the auth-broker adapter is consumed server-side
  by the `zaakportaal-mijngemeente` intake flow. The simulator login form / journey lands with
  that beta surface; the adapter contract (BSN validation, simulator flagging) is proven by
  PHPUnit here.

### e-Depot (Nationaal Archief) — beta (customer-side aansluittraject)

- The e-Depot **submission transport** is OpenRegister's (`Edepot/Transport/*`) after
  `migrate-archival-to-or` retires procest's `EDepotSubmissionAdapterInterface`. This change
  contributes only: (i) offline **MDTO XSD validation** of generated SIPs (lands with the OR
  archival pipeline — `NationaalArchief/MDTO-XSD`), and (ii) a manual **Preservica Starter**
  (`https://starter.preservica.com`, free 5 GB tier) sandbox rehearsal lane.
- **Access request**: the NA e-Depot **aansluittraject** (impact analysis + intake) is only open to
  zorgdragers/overheden and takes **months** — it is a **customer-side track procest supports but
  cannot initiate**, and is OUT OF SESSION SCOPE. Note: MDTO supersedes the legacy TMLO naming.

## Access-request register

| Integration | Grant | Status (this session) |
|-------------|-------|------------------------|
| BRP proefomgeving X-API-KEY | e-mail to BRP-API product owner | not requested (offline mock lane wired) |
| KvK test key | public — no request needed | ✅ in use (public test key) |
| DSO pre-prod (client_id + test key + PKIoverheid cert) | aansluittraject | blocked — formal aansluittraject, customer-side, out of scope |
| Logius DigiD preproductie + PKIoverheid cert | supplier application | blocked — formal aansluiting, out of scope (simulator shipped) |
| Preservica Starter | instant signup | not exercised (belongs with migrate-archival-to-or) |

## What could not be verified in this session

- No live external calls were made against BRP proefomgeving, DSO pre-prod, Logius preprod, or a
  real e-Depot — those require credentials/certificates from formal aansluittrajecten that are
  customer-side and out of session scope. The adapters + config tiers + offline/recorded contract
  lanes are shipped and green; promotion of BRP/KvK from beta → stable is gated on an end-to-end
  run against the official environment once the credential is granted.
