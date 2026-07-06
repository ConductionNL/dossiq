# Proposal: external-integrations-test-environments

kind: integration hardening — finish procest's external-integration seams against **real test
environments** instead of leaving them on Mock/Log adapters forever. Product-owner decision
2026-07-05: "external integrations should be finished against real test environments (find them)."
Cites ADR-019 (integration registry) and ADR-022; complements `pluggable-integration-registry`
(ACTIVE) which owns the *seam mechanics* — this change owns *what the seams talk to*.

## Why

A 2026-07-05 audit confirmed procest's ~19 external integrations are interface + Mock/Log adapter
seams with the mock permanently bound in `lib/AppInfo/Application.php` (lines 296–359): nothing
procest "integrates with" is ever actually called. The five that gate municipal adoption — BRP,
KvK, DSO/Omgevingswet, DigiD/eHerkenning, e-Depot — all have findable test facilities. Web
research (2026-07-05, verified against official pages where fetchable; caveats noted) produced:

## Test-environment research results

| Integration | Test env / mock | URL | Access requirement | Cost | Lead time | CI docker mock? |
|---|---|---|---|---|---|---|
| **BRP** (Haal Centraal Personen bevragen) | Official proefomgeving (GBA-V testset) + official OSS mock | `https://proefomgeving.haalcentraal.nl/haalcentraal/api/brp`; mock image `ghcr.io/brp-api/personen-mock` (repo `github.com/BRP-API/Haal-Centraal-BRP-bevragen`; the old VNG-Realisatie repo redirects there) | Proefomgeving: API key by e-mail from the product owner (X-API-KEY); mock: open | Free | Proefomgeving: ad-hoc e-mail; mock: instant | **YES** — `personen-mock` runs fully offline (port 5010, `/haalcentraal/api/brp/personen`), test data via volume-mapped `test-data.json` |
| **KvK** (Handelsregister) | KVK Developer Portal testomgeving | `https://api.kvk.nl/test/api/v2/zoeken` (+ basisprofielen v1, vestigingsprofielen v1, naamgevingen v1) | **Open** — shared public test key `l7xx1f2691f2520d487b902f4e0b57a0b197` (verified live on developers.kvk.nl testing page), no registration | Free | None (instant) | Partial — no docker image; hosted test endpoint with a fixed set of 20+ fictitious companies (e.g. KVK 69599084, 68750110, 69599068, 55344526) |
| **DSO / Omgevingswet** | Pre-productie / oefenomgeving (Omgevingsloket) via Ontwikkelaarsportaal | `https://developer.omgevingswet.overheid.nl`; e.g. `https://service.pre.omgevingswet.overheid.nl/publiek/verzoeken/api/indienen/v1`; intake via aansluiten.omgevingswet.overheid.nl / DSO aansluitteam | **Formal aansluittraject**: service-request form → client_id + test API key; PKIoverheid certificate with OIN/HRN for REST calls | Free | ~5 working days per handling step (aansluitteam) | **NO** — no mock; remote pre-prod + certificate only |
| **DigiD / eHerkenning** (Logius) | Preproductieomgeving DigiD; OSS mock `maykinmedia/django-digid-eherkenning` (Open Formulieren) | Logius aanvraagformulier ("Voorwaarden Preproductieomgeving DigiD (Leverancier)" — private-law suppliers MAY apply, not only public bodies); PyPI `django-digid-eherkenning` | Preprod: registration form + PKIoverheid cert for the SAML koppelvlak; mock: open | Free (PKIoverheid cert itself costs) | Preprod: weeks (PKIoverheid subscription can take several weeks); mock: instant | Partial — the mock is a Django library (mock login form + BSN), Docker only inside the Open Forms stack; **no standalone SAML-artifact IdP image** |
| **e-Depot** (Nationaal Archief) | NA e-Depot aansluittraject; MDTO validators; Preservica Starter | NA kennisbank (aansluittraject); `github.com/NationaalArchief/MDTO-XSD` (+ `nationaalarchief.github.io/MDTO-XSD`, MDTO-Metagegevensschema); community validator `github.com/Regionaal-Archief-Rivierenland/mdto.py`; `https://starter.preservica.com` (free 5GB hosted tier) | Aansluittraject: zorgdragers/overheden only, impact analysis + intake; MDTO tooling: open; Preservica Starter: open signup | Free (for eligible bodies / Starter tier) | Aansluittraject: **months**; MDTO/Starter: instant | Partial — no e-Depot mock, but **MDTO XSD validation runs fully offline in CI**; Preservica Starter is a hosted manual sandbox, not a CI container |

Research caveats (recorded for honesty): GitHub HTML was not directly fetchable during research —
the exact current `personen-mock` tag, the BRP product-owner contact address, and the
digid-eherkenning docker wiring come from official github.io docs + search snippets; DC01 pins
them. KvK key, DSO pre-prod endpoint, DigiD supplier-eligibility, and Preservica facts were
verified on official pages. Note: **MDTO supersedes TMLO** — procest's TMLO naming is legacy;
target MDTO.

## What Changes (per integration: seam → test env, behind config)

Every integration keeps its existing interface; a real adapter is added next to the Mock/Log one
and selected by config (default remains mock — dev instances never call external services
unknowingly). Contract tests run per tier: offline docker mock in CI where one exists, hosted test
env in a gated/nightly lane, formal pre-prod manually.

1. **BRP** — seam `lib/Service/External/Brp/BrpHaalCentraalAdapterInterface.php`, current binding
   `LogBrpHaalCentraalAdapter` (`Application.php:342-343`). Add `HaalCentraalBrpAdapter`
   (X-API-KEY, base-URL config). CI: `ghcr.io/brp-api/personen-mock` service container; nightly:
   proefomgeving once the key is granted.
2. **KvK** — seam `lib/Service/External/Kvk/KvkHandelsregisterAdapterInterface.php`, current
   binding `LogKvkHandelsregisterAdapter` (`Application.php:338-339`). Add `KvkApiAdapter` against
   `api.kvk.nl/test` with the public test key and the fixed fictitious-company set as contract
   fixtures. Runs in CI's network-allowed lane (no docker mock exists).
3. **DSO / Omgevingswet** — seam is config-based today: `DsoLvAuthService` reads bearer token
   `dso_lv_auth_token` from app config and returns empty headers when unset;
   `DsoIntakeService`/`DsoCaseService` consume it. Start the aansluittraject (service request →
   client_id + test key; PKIoverheid OIN/HRN cert), point the config at
   `service.pre.omgevingswet.overheid.nl`, and add contract tests against recorded pre-prod
   fixtures (live pre-prod lane is manual/gated — certificate-bound).
4. **DigiD / eHerkenning** — seams `lib/Service/Auth/DigidSamlAdapterInterface.php` →
   `LogDigidSamlAdapter` (`Application.php:313-314`) and `EHerkenningSamlAdapterInterface` →
   `LogEHerkenningSamlAdapter` (`Application.php:309-310`). Two steps: (i) a procest
   `MockDigidSamlAdapter`-grade *simulator* adapter modelled on the maykinmedia mock-login pattern
   (BSN entry form, no real SAML) for demo/e2e; (ii) the real SAML-artifact adapter validated
   against **Logius preproductie** — supplier application is allowed for private-law orgs; needs
   PKIoverheid cert (weeks of lead time; start early). Full SAML behaviour is only provable on
   preprod, not on the mock.
5. **e-Depot** — the submission seam after `migrate-archival-to-or` is **OpenRegister's**
   `Edepot/Transport/TransportInterface` (procest's `EDepotSubmissionAdapterInterface` +
   `LogEDepotSubmissionAdapter`, `Application.php:350-351`, is retired by that change). This
   change contributes: (i) CI-offline **MDTO XSD validation** of every generated SIP
   (NationaalArchief/MDTO-XSD + mdto.py); (ii) a manual sandbox lane against **Preservica
   Starter** via OR's RestApi/Sftp transport; (iii) the NA aansluittraject is recorded as a
   customer-side (zorgdrager) track procest can only support, not initiate.

## Promotion criteria (features overlay, per integration)

`mock → beta`: real adapter exists, selected by config, contract tests green against the
integration's best automatable tier (docker mock / hosted test env / recorded fixtures).
`beta → stable`: validated against the official test/pre-prod environment end-to-end (proefomgeving
key, KvK test endpoint, DSO pre-prod with cert, Logius preprod, e-Depot ingest confirmation on a
sandbox), an operator runbook exists in `docs/admin/`, and failure modes (timeout, auth failure,
malformed response) degrade without data loss. Downgrades follow the same gates in reverse.

## Overlap with `brp-kvk-register-sets` (ACTIVE — extend, don't duplicate)

That change owns the **register-side**: BRP/KvK schemas + 10 fictitious seed persons/companies +
initiator selection UI, and explicitly flags "Real BRP/KVK integration would require Haal Centraal
and KVK API credentials" as its risk. This change is that missing half: the live adapters. Concrete
interlocks: (i) the seed datasets are aligned with the official fixture sets (BRP mock
`test-data.json` personas; KvK's fictitious companies) so demo data and contract fixtures are the
same objects; (ii) initiator search falls back register → live adapter per config tier. A pointer
note is added to that change's proposal (this change's T-note task) rather than duplicating its
requirements here.

## Impact

- New adapters under `lib/Service/External/{Brp,Kvk}/`, `lib/Service/Auth/`; config-driven
  binding in `Application.php` replacing the hardcoded mock aliases; admin settings for
  endpoint/key/cert per integration.
- CI: docker-mock service (BRP), network-gated Newman/PHPUnit contract lanes (KvK), recorded
  fixtures (DSO), MDTO XSD validation step (e-Depot). API contract tests are Newman/PHPUnit —
  Playwright only for the DigiD simulator login journey (UI).
- `openspec/features.overlay.json`: per-integration feature entries follow the promotion criteria.
- Out-of-scope seams (Berichtenbox `BerichtenboxAdapterInterface`→`MockAdapter`, beschikking
  signing `SigningAdapterInterface`→`MockSigningAdapter`, ZTC, ZGW-external push, template engine)
  stay mock: no municipal test facility was identified for them in this round; they follow the
  same pattern later.

## Out of Scope

- Production connections (production DigiD aansluiting, production KvK contract, NA ingest) —
  customer-side procurement/aansluittrajecten.
- The seam *mechanics* refactor — `pluggable-integration-registry` (ACTIVE) owns how adapters are
  registered/selected; this change binds real endpoints through whatever that change lands (or
  plain config aliases if it lands later — DC02 sequences this).
- Retiring the e-Depot submission seam — `migrate-archival-to-or`.
