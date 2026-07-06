# Design: external-integrations-test-environments

## Config-tier model (uniform across integrations)

Per integration one app-config key selects the adapter tier, plus tier-specific credentials:

| Key | Values | Default |
|---|---|---|
| `integration.brp.mode` | `log` \| `mock` (docker mock URL) \| `test` (proefomgeving) \| `live` | `log` |
| `integration.brp.baseUrl` / `integration.brp.apiKey` | — | unset |
| `integration.kvk.mode` | `log` \| `test` \| `live` | `log` |
| `integration.kvk.baseUrl` / `integration.kvk.apiKey` | test default `https://api.kvk.nl/test/api`, public test key | unset |
| `dso_lv_auth_token` (existing) + `integration.dso.baseUrl` + cert config | — | unset (DsoLvAuthService already fail-opens to empty headers + warning) |
| `integration.digid.mode` | `log` \| `simulator` \| `preprod` \| `live` | `log` |
| e-Depot | none in procest — transport config lives in OR (`Edepot/Transport/*`, EdepotSettingsController) | — |

`Application.php` replaces the fixed `registerServiceAlias(Interface, LogAdapter)` calls with a
factory reading the mode key; unknown/unset mode binds the Log adapter (fail-closed to no external
calls). If `pluggable-integration-registry` lands first, the factory is expressed through that
registry instead (DC02).

## Adapter inventory (seam → new adapter → test target)

| Seam interface (existing) | Current adapter (existing) | New adapter | Automated test target |
|---|---|---|---|
| `External/Brp/BrpHaalCentraalAdapterInterface` | `LogBrpHaalCentraalAdapter` | `HaalCentraalBrpAdapter` | CI service container `ghcr.io/brp-api/personen-mock` on :5010; nightly proefomgeving |
| `External/Kvk/KvkHandelsregisterAdapterInterface` | `LogKvkHandelsregisterAdapter` | `KvkApiAdapter` | `api.kvk.nl/test` public key, fixed fictitious-company fixtures |
| DSO (config seam via `DsoLvAuthService`) | token unset → warn + empty headers | same services, real token/cert config | recorded pre-prod fixtures in CI; live pre-prod manual (cert) |
| `Auth/DigidSamlAdapterInterface` | `LogDigidSamlAdapter` | `SimulatorDigidSamlAdapter` (BSN form, maykinmedia pattern) + `SamlDigidAdapter` | simulator in e2e; SAML only on Logius preprod (manual) |
| `Auth/EHerkenningSamlAdapterInterface` | `LogEHerkenningSamlAdapter` | same two-step as DigiD | idem |
| e-Depot: OR `Edepot/Transport/TransportInterface` (post `migrate-archival-to-or`) | OR mock/log transport | OR RestApi/Sftp transport config | MDTO-XSD validation offline in CI; Preservica Starter manual sandbox |

## Contract-test layout

- `tests/integration/contracts/brp/` — PHPUnit contract suite hitting the adapter against the BRP
  docker mock; fixtures = the mock's `test-data.json` personas (shared with
  `brp-kvk-register-sets` seed data).
- `tests/integration/contracts/kvk/` — same shape against `api.kvk.nl/test`; tagged
  `@group network` and run in the network-allowed CI lane only; assertions pinned to the published
  fictitious companies (KVK 69599084 eenmanszaak, 68750110 BV, 69599068 stichting, 55344526
  coöperatie).
- `tests/integration/contracts/dso/` — recorded request/response fixtures from pre-prod
  (refreshed manually when the aansluittraject grants access); the live lane is a manual runbook.
- DigiD simulator journey — Playwright e2e (login-with-BSN through the simulator adapter); SAML
  contract itself is NOT simulated (honesty rule: the mock does not prove SAML).
- e-Depot — CI step validating every generated SIP's MDTO XML against `NationaalArchief/MDTO-XSD`
  (mdto.py or xmllint); no fake ingest assertions.

CI wiring: BRP mock as a compose service in the existing pipeline; network lane nightly or
label-gated so PR CI stays offline-deterministic.

## Lead-time-driven sequencing

Start the slow paperwork immediately, code against the fast tiers meanwhile:

1. Week 0: file the Logius preprod application + PKIoverheid cert request (weeks) and the DSO
   service request (~5 working days/step); e-mail the BRP proefomgeving key request.
2. Week 0+: BRP (docker mock) and KvK (open test key) adapters + contract tests — no waiting.
3. On grant: DSO pre-prod smoke + fixture recording; Logius preprod SAML validation.
4. e-Depot: MDTO validation lands with `migrate-archival-to-or`'s OR pipeline; Preservica Starter
   sandbox exercised manually for an end-to-end ingest rehearsal.

## Promotion mechanics

Feature flags in `openspec/features.overlay.json` move per the criteria in the proposal; each
promotion PR must link the green contract-lane run (mock→beta) or the official-environment
evidence + `docs/admin/` runbook (beta→stable). Demotion uses the same evidence in reverse.
