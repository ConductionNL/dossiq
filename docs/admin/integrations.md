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
| `integration.dso.baseUrl` | `https://service.pre.omgevingswet.overheid.nl` | unset |

Set a tier with, e.g.:

```bash
occ config:app:set dossiq integration.dso.baseUrl --value https://service.pre.omgevingswet.overheid.nl
```

An unknown or unset mode always falls back to `log`.

## Per-integration status

### BRP (family relations for the conflict-of-interest check)

Dossiq ships no BRP client. Integriq holds the BRP source. Dossiq asks it one question: who are this case worker's partner, parents and children? The conflict-of-interest check uses the answer to stop a worker deciding on a case of their own family.

The read is off by default. Switch it on in three steps:

1. Check your BRP authorisation (autorisatiebesluit) from RvIG. It must cover the burgerservicenummer of parents, partner and children for this task.
2. Open the `brp-haalcentraal` source in Integriq and set `relationFields` to `true` in its configuration.
3. Decide on a test case as a worker whose partner is the applicant. Dossiq refuses, with the reason `partner`.

What you see while it is off: the check still compares the worker with the applicant, and its answer carries `relationCheck: not-available` with the reason. Nothing is asked of the BRP.

What you see when it is on and the BRP does not answer: the decision is refused with `relatie_onbepaald`. Dossiq does not treat silence as "no relation".

The keys `integration.brp.mode`, `integration.brp.baseUrl` and `integration.brp.apiKey` are retired. Setting them changes nothing. Remove them with `occ config:app:delete dossiq integration.brp.mode`, and the same for the other two.

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
| DSO pre-prod (client_id + test key + PKIoverheid cert) | aansluittraject | blocked — formal aansluittraject, customer-side, out of scope |
| Logius DigiD preproductie + PKIoverheid cert | supplier application | blocked — formal aansluiting, out of scope (simulator shipped) |
| Preservica Starter | instant signup | not exercised (belongs with migrate-archival-to-or) |

## What could not be verified in this session

- No live external calls were made against DSO pre-prod, Logius preprod, or a
  real e-Depot — those require credentials/certificates from formal aansluittrajecten that are
  customer-side and out of session scope. The adapters + config tiers + offline/recorded contract
  lanes are shipped and green. The BRP and KvK sources live in Integriq, and their live pass is
  Integriq's.
